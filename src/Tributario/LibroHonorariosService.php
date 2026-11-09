require_once __DIR__ . '/CalculadoraHonorariosService.php';

class LibroHonorariosService {

    private PDO $pdo;
    private AsientoService $asientoService;

    public function __construct(PDO $pdo, AsientoService $asientoService) {
        $this->pdo = $pdo;
        $this->asientoService = $asientoService;
    }

    /**
    *Registra una Boleta de Honorarios Electrónica (BHE), despacha su asiento contable 
    *inmutable y registra la auditoría correspondiente dentro de un bloque ACID.
    * 
    *@param int $empresaId ID de la empresa (Multi-tenancy)
    *@param int $periodoId ID del período fiscal activo 
    *@param array $datos {
    *   @var int | string $numero_boleta Folio o número de la BHE
    *   @var string $rut_profesional RUT del prestador (ej: "12345678-9")
    *   @var string $razon_social Nombre o Razón Social del profesional
    *   @var string $fecha_emision Fecha de la BHE (YYYY-MM-DD)
    *   @var string $modo_calculo 'BRUTO' o 'LIQUIDO'
    *   @var string $monto_ingresado Monto inicial a procesar
    *   @var string $glosa Concepto o descripción del servicio
    *   @var int $cuenta_gasto_id ID cuenta contable DEBE (Gasto Honorarios)
    *   @var int $cuenta_retencion_id ID cuenta contable HABER (Pasivo F29 14.25%)
    *   @var int $cuenta_por_pagar_id ID cuenta contable HABER (Pasivo Prestador)
    *   @var int $usuario_id ID del usuario que registra
    * }
    *@return array ['boleta_id' => int, 'asiento_id' => int, 'desglose' => array]
    *@throws Exception Si falla alguna validación, existe duplicidad o error en la transacción.
    */
    public function registrarBoleta(int $empresaId, int $periodoId, array $datos): array {
        // 1. Sanitización y validación previa de la entrada
        $this->validarEntrada($datos);

        $rutProfesional = $this->sanitizarYValidarRut((string)$datos['rut_profesional']);
        $numeroBoleta   = (int)$datos['numero_boleta'];
        $modoCalculo    = strtoupper(trim((string)$datos['modo_calculo']));
        $montoIngresado = trim((string)$datos['monto_ingresado']);

        // 2. Validación de Unicidad Documental (Fail-Fast antes de iniciar transacción)
        if ($this->existeBoletaDuplicada($empresaId, $rutProfesional, $numeroBoleta)) {
            throw new Exception("La Boleta de Honorarios N° {$numeroBoleta} para el RUT {$rutProfesional} ya existe en esta empresa.");
        }

        // 3. Invocación al servicio estático de cálculo tributario (Chile 2026 - Tasa 14.25%)
        if ($modoCalculo === 'BRUTO') {
            $desglose = CalculadoraHonorariosService::calcularDesdeBruto($montoIngresado);
        } elseif ($modoCalculo === 'LIQUIDO') {
            $desglose = CalculadoraHonorariosService::calcularDesdeLiquido($montoIngresado);
        } else {
            throw new Exception("El modo de cálculo debe ser 'BRUTO' o 'LIQUIDO'.");
        }

        // Formatear enteros a strings con 4 decimales ('0.0000') para BBDD y AsientoService
        $brutoDecim      = bcadd($desglose['bruto'], '0', 4);
        $retencionDecim  = bcadd($desglose['retencion'], '0', 4);
        $liquidoDecim    = bcadd($desglose['liquido'], '0', 4);

        // 4 Bloque Transaccional ACID Global
        $this->pdo->beginTransaction();

        try {
            // A. Insertar registro tributario en `libro_honorarios`
            $sqlBoleta = "INSERT INTO libro_honorarios (
                            empresa_id, periodo_id, numero_boleta, rut_profesional, razon_social,
                            fecha_emision, monto_bruto, monto_retencion, monto_liquido,
                            tasa_retencion, modo_calculo, glosa, estado, creado_por, creado_en
                        ) VALUES (
                            :empresa_id, :periodo_id, :numero_boleta, :rut_profesional, :razon_social,
                            :fecha_emision, :monto_bruto, :monto_retencion, :monto_liquido,
                            '0.1425', :modo_calculo, :glosa, 'VIGENTE', :creado_por, NOW()
                        )";
            $stmtBoleta = $this->pdo->prepare($sqlBoleta);
            $stmtBoleta->execute([
                ':empresa_id'       => $empresaId,
                ':periodo_id'       => $periodoId,
                ':numero_boleta'    => $numeroBoleta,
                ':rut_profesional'  => $rutProfesional,
                ':razon_social'     => trim((string)$datos['razon_social']),
                ':fecha_emision'    => $datos['fecha_emision'],
                ':monto_bruto'      => $brutoDecim,
                ':monto_retencion'  => $retencionDecim,
                ':monto_liquido'    => $liquidoDecim,
                ':modo_calculo'     => $modoCalculo,
                ':glosa'            => trim((string)$datos['glosa']),
                ':creado_por'       => (int)$datos['usuario_id']
            ]);

            $boletaId = (int)$this->pdo->lastInsertId();

            // B. Construir matriz contable unidireccional (DEBE / HABER estricto)
            $lineasAsiento = [
                // Línea 1 (DEBE): Gasto por Honorarios -> Monto Bruto
                [
                    'cuenta_id' => (int)$datos['cuenta_gasto_id'],
                    'debe'      => $brutoDecim,
                    'haber'     => '0.0000'
                ],
                // Línea 2 (HABER): Retención Honorarios F29 -> Monto Retención (14.25%)
                [
                    'cuenta_id' => (int)$datos['cuenta_retencion_id'],
                    'debe'      => '0.0000',
                    'haber'     => $retencionDecim
                ],
                // Línea 3 (HABER): Honorarios por Pagar -> Monto Líquido
                [
                    'cuenta_id' => (int)$datos['cuenta_por_pagar_id'],
                    'debe'      => '0.0000',
                    'haber'     => $liquidoDecim
                ]
            ];

            // C. Despachar a AsientoService respetando los 5 parámetros posicionales
            $glosaAsiento = "BHE N° {$numeroBoleta} - " . trim((string)$datos['razon_social']);

            $asientoId = $this->asientoService->registrarAsiento(
                $empresaId,
                $periodoId,
                (string)$datos['fecha_emision'],
                $glosaAsiento,
                $lineasAsiento
            );

            // D. Vincular `asiento_id` generado a la BHE
            $sqlUpdate = "UPDATE libro_honorarios SET asiento_id = :asiento_id WHERE id = :id";
            $stmtUpdate = $this->pdo->prepare($sqlUpdate);
            $stmtUpdate->execute([
                ':asiento_id' => $asientoId,
                ':id'         => $boletaId
            ]);

            // E. Registrar log de auditoria inalterable
            $sqlAuditoria = "INSERT INTO bitacora_auditoria (
                                empresa_id, accion, tabla_afectada, registro_id, detalles, ip_origen
                            ) VALUES (
                                :empresa_id, 'INSERT', 'libro_honorarios', :registro_id, :detalles, :ip
                            )";
            $stmtAuditoria = $this->pdo->prepare($sqlAuditoria);
            $stmtAuditoria->execute([
                ':empresa_id'   => $empresaId,
                ':registro_id'  => $boletaId,
                ':detalles'     => json_encode([
                    'numero_boleta'     => $numeroBoleta,
                    'rut_profesional'   => $rutProfesional,
                    'monto_bruto'       => $desglose['bruto'],
                    'asiento_id'        => $asientoId
                ]),
                ':ip'           => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
            
            // Commit atómico final 
            $this->pdo->commit();

            return [
                'boleta_id'     => $boletaId,
                'asiento_id'    => $asientoId,
                'desglose'      => $desglose    
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new Exception("Error al procesar la Boleta de Honorarios: " . $e->getMessage(), 0, $e);
        }
    }

    /**
    *Verifica la existencia previa de la BHE en MySQL para evitar violación de unicidad.
    */
    private function existeBoletaDuplicada(int $empresaId, string $rut, int $numero): bool {
        $sql = "SELECT COUNT(*) FROM libro_honorarios 
                WHERE empresa_id = :empresa_id AND rut_profesional = :rut AND numero_boleta = :numero";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':empresa_id' => $empresaId,
            ':rut'        => $rut,
            ':numero'     => $numero
        ]);
        return ((int)$stmt->fetchColumn()) > 0;
    }

    /**
    *Valida que todos los parámetros obligatorios estén presentes en la petición.
    */
    private function validarEntrada(array $datos): void {
        $requeridos = [
            'numero_boleta', 'rut_profesional', 'razon_social', 'fecha_emision',
            'modo_calculo', 'monto_ingresado', 'glosa',
            'cuenta_gasto_id', 'cuenta_retencion_id', 'cuenta_por_pagar_id', 'usuario_id'
        ];

        foreach ($requeridos as $campo) {
            if (!isset($datos[$campo]) || trim((string)$datos[$campo]) === '') {
                throw new Exception("El parámetro '{$campo}' es obligatorio para el registro de la BHE.");
            }
        }
    }

    /**
    *Valida el algoritmo del dígito verificador del RUT chileno (Módulo 11).
    *Garantiza que el cuerpo sea 100% numérico y sin caracteres intermedios.
    */
    private function sanitizarYValidarRut(string $rut): string {
        // 1. Limpiar puntos y guion, dejando solo dígitos y K/k
        $rutLimpio = preg_replace('/[^0-9kK]/', '', trim($rut));

        if (strlen($rutLimpio) < 7) {
            throw new Exception("El RUT ingresado ('{$rut}') no es válido.");
        }

        $cuerpo = substr($rutLimpio, 0, -1);
        $dv     = strtoupper(substr($rutLimpio, -1));

        // 2. Validación estricta: El cuerpo DEBE contener ÚNICAMENTE dígitos
        if (!ctype_digit($cuerpo)) {
            throw new Exception("El cuerpo del RUT '{$rut}' contiene caracteres no numéricos no válidos.");
        }

        // 3. Algoritmo Módulo 11
        $suma = 0;
        $multiplicador = 2;

        for ($i = strlen($cuerpo) - 1; $i >= 0; $i--) {
            $suma += (int)$cuerpo[$i] * $multiplicador;
            $multiplicador = ($multiplicador === 7) ? 2 : $multiplicador + 1;
        }

        $resto = $suma % 11;
        $dvEsperado = 11 - $resto;

        if ($dvEsperado === 11) {
            $dvCalculado = '0';
        } elseif ($dvEsperado === 10) {
            $dvCalculado = 'K';
        } else {
            $dvCalculado = (string)$dvEsperado;
        }

        if ($dv !== $dvCalculado) {
            throw new Exception("El RUT '{$rut}' es inválido según el algoritmo Módulo 11 del SII.");
        }

        return $cuerpo . '-' . $dv;
    }
}