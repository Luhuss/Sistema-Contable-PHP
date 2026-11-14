<?php

// src/Tributario/ServicioAnulacionHonorariosService.php

require_once __DIR__ . '/../Contabilidad/AsientoService.php';

class ServicioAnulacionHonorariosService {

    private PDO $pdo;
    private AsientoService $asientoService;

    public function __construct(PDO $pdo, AsientoService $asientoService) {
        $this->pdo = $pdo;
        $this->asientoService = $asientoService;
    }

    /**
     *Anula una Boleta de Honorarios Electrónica (BHE) emitiendo un contraasiento contable
     *e inhabilitando su registro tributario dentro de una transacción ACID global.
     *
     *@param int $empresaId ID de la empresa (Multi-tenancy)
     *@param int $boletaId ID de la boleta de honorarios a anular
     *@param int $usuarioId ID del usuario que ejecuta la anulación
     *@param string $motivo Razón o justificación de la anulación
     *@return array ['boleta_id' => int, 'contraasiento_id' => int]
     *@throws Exception Si la boleta no existe, ya está anulada o falla la transacción.
     */
    public function anularBoleta(int $empresaId, int $boletaId, int $usuarioId, string $motivo): array {
        $motivoLimpio = trim($motivo);
        if ($motivoLimpio === '') {
            throw new Exception("Debe especificar un motivo válido para la anulación de la boleta.");
        }

        // 1. Obtener registro de la BHE y verificar pertenencia y estado (Fail-Fast)
        $sqlBoleta = "SELECT id, periodo_id, numero_boleta, rut_profesional, razon_social, 
                            fecha_emision, asiento_id, estado
                    FROM libro_honorarios 
                    WHERE id = :id AND empresa_id = :empresa_id";
        $stmtBoleta = $this->pdo->prepare($sqlBoleta);
        $stmtBoleta->execute([
            ':id'         => $boletaId,
            ':empresa_id' => $empresaId
        ]);
        $boleta = $stmtBoleta->fetch(PDO::FETCH_ASSOC);

        if (!$boleta) {
            throw new Exception("La Boleta de Honorarios ID {$boletaId} no existe o no pertenece a la empresa.");
        }

        if ($boleta['estado'] === 'ANULADA') {
            throw new Exception("La Boleta de Honorarios N° {$boleta['numero_boleta']} ya se encuentra anulada.");
        }

        if (empty($boleta['asiento_id'])) {
            throw new Exception("La Boleta de Honorarios N° {$boleta['numero_boleta']} no tiene un asiento contable vinculado.");
        }

        $asientoIdOriginal = (int)$boleta['asiento_id'];
        $periodoId         = (int)$boleta['periodo_id'];

        // 2. Bloque Transaccional ACID Global
        $this->pdo->beginTransaction();

        try {
            // A. Recuperar el detalle del asiento contable original para invertir sus montos
            $sqlDetalle = "SELECT cuenta_id, debe, haber 
                            FROM detalle_asientos 
                            WHERE asiento_id = :asiento_id 
                            ORDER BY id ASC";
            $stmtDetalle = $this->pdo->prepare($sqlDetalle);
            $stmtDetalle->execute([':asiento_id' => $asientoIdOriginal]);
            $lineasOriginales = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

            if (empty($lineasOriginales)) {
                throw new Exception("No se encontraron apuntes contables para el asiento original N° {$asientoIdOriginal}.");
            }

            // B. Construir matriz de contraasiento (Inversión DEBE <-> HABER)
            $lineasContraasiento = [];
            foreach ($lineasOriginales as $linea) {
                $lineasContraasiento[] = [
                    'cuenta_id' => (int)$linea['cuenta_id'],
                    'debe'      => bcadd((string)$linea['haber'], '0', 4), // El HABER pasa al DEBE
                    'haber'     => bcadd((string)$linea['debe'], '0', 4)   // El DEBE pasa al HABER
                ];
            }

            // C. Despachar el contraasiento a AsientoService
            $glosaContraasiento = "ANULACION BHE N° {$boleta['numero_boleta']} - " . trim($boleta['razon_social']) . " [Motivo: {$motivoLimpio}]";

            $contraasientoId = $this->asientoService->registrarAsiento(
                $empresaId,
                $periodoId,
                (string)$boleta['fecha_emision'],
                $glosaContraasiento,
                $lineasContraasiento
            );

            // D. Actualizar estado de la BHE en `libro_honorarios`
            $sqlUpdateBoleta = "UPDATE libro_honorarios 
                                SET estado = 'ANULADA', 
                                    actualizado_en = NOW() 
                                WHERE id = :id AND empresa_id = :empresa_id";
            $stmtUpdate = $this->pdo->prepare($sqlUpdateBoleta);
            $stmtUpdate->execute([
                ':id'         => $boletaId,
                ':empresa_id' => $empresaId
            ]);

            // E. Registrar trazabilidad inalterable en la bitácora de auditoría
            $sqlAuditoria = "INSERT INTO bitacora_auditoria (
                                empresa_id, accion, tabla_afectada, registro_id, detalles, ip_origen
                            ) VALUES (
                                :empresa_id, 'UPDATE_ANULACION', 'libro_honorarios', :registro_id, :detalles, :ip
                            )";
            $stmtAuditoria = $this->pdo->prepare($sqlAuditoria);
            $stmtAuditoria->execute([
                ':empresa_id'  => $empresaId,
                ':registro_id' => $boletaId,
                ':detalles'    => json_encode([
                    'numero_boleta'       => $boleta['numero_boleta'],
                    'rut_profesional'     => $boleta['rut_profesional'],
                    'asiento_original_id' => $asientoIdOriginal,
                    'contraasiento_id'    => $contraasientoId,
                    'motivo'              => $motivoLimpio,
                    'usuario_id'          => $usuarioId
                ]),
                ':ip'          => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            // Commit atómico global
            $this->pdo->commit();

            return [
                'boleta_id'         => $boletaId,
                'contraasiento_id'  => $contraasientoId
            ];

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new Exception("Error al anular la Boleta de Honorarios: " . $e->getMessage(), 0, $e);
        }
    }
}