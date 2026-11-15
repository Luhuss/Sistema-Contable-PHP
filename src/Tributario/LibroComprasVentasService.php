<?php

declare(strict_types=1);

require_once __DIR__ . '/CalculadoraImpuestosService.php';
require_once __DIR__ . '/../Contabilidad/AsientoService.php';
require_once __DIR__ . '/../Auditoria/AuditoriaService.php';

/**
 * Servicio Orquestador del Libro de Compras y Ventas (RCV - Normativa SII Chile 2026).
 *
 * Gestiona el registro tributario inmutable de DTEs (Facturas 33/34/46, Boletas 39/41,
 * Notas de Débito 56 y Notas de Crédito 61), desglose con BCMath / CalculadoraImpuestosService,
 * generación automática de asientos contables en partida doble y resumen F29.
 */
class LibroComprasVentasService
{
    private const OPERACIONES_VALIDAS = ['COMPRA', 'VENTA'];

    /**
     * Códigos DTE SII soportados:
     * 33 = Factura Electrónica Afecta
     * 34 = Factura No Afecta o Exenta Electrónica
     * 39 = Boleta Electrónica
     * 41 = Boleta No Afecta o Exenta Electrónica
     * 46 = Factura de Compra Electrónica
     * 56 = Nota de Débito Electrónica
     * 61 = Nota de Crédito Electrónica
     */
    private const TIPOS_DTE_VALIDOS = [33, 34, 39, 41, 46, 56, 61];
    private const DTE_EXENTOS       = [34, 41];
    private const TASA_IVA_DEFAULT  = '0.1900';

    private PDO $pdo;
    private AsientoService $asientoService;
    private AuditoriaService $auditoriaService;

    public function __construct(
        PDO $pdo,
        AsientoService $asientoService,
        ?AuditoriaService $auditoriaService = null
    ) {
        $this->pdo = $pdo;
        $this->asientoService = $asientoService;
        $this->auditoriaService = $auditoriaService ?? new AuditoriaService($pdo);
    }

    /**
     * Registra un documento tributario en `libro_compras_ventas`, genera su asiento
     * contable inmutable (incluyendo inversión automática para Nota de Crédito 61)
     * y persiste la trazabilidad en `bitacora_auditoria` dentro de un bloque ACID.
     *
     * @param int   $empresaId ID de la empresa (Multi-tenancy)
     * @param int   $periodoId ID del período fiscal activo
     * @param array $datos {
     *     @var string      $tipo_operacion               'COMPRA' o 'VENTA'
     *     @var int|string  $tipo_documento_sii           33, 34, 39, 41, 46, 56 o 61
     *     @var int|string  $folio                        Folio del DTE
     *     @var string      $rut_contraparte              RUT emisor/receptor
     *     @var string      $razon_social_contraparte     Nombre o Razón Social
     *     @var string      $fecha_emision                Fecha emisión (YYYY-MM-DD)
     *     @var string|null $fecha_recepcion_sii          Fecha acuse/recepción SII (opcional)
     *     @var string      $modo_ingreso                 'BRUTO', 'NETO' o 'DESGLOSADO'
     *     @var string|null $monto_ingresado              Requerido si modo_ingreso es 'BRUTO' o 'NETO'
     *     @var string|null $monto_exento                 Opcional (default '0')
     *     @var string|null $monto_neto                   Requerido si modo_ingreso es 'DESGLOSADO'
     *     @var string|null $monto_iva                    Opcional en 'DESGLOSADO' (si es null se calcula 19%)
     *     @var string|null $iva_no_recuperable           Opcional en COMPRAS (default '0')
     *     @var string|null $codigo_impuesto_adicional    Opcional (ej. 'ILA', 'COMB')
     *     @var string|null $monto_impuesto_adicional     Opcional (default '0')
     *     @var string      $glosa                        Descripción contable
     *     @var int         $cuenta_contraparte_id        Cuenta Clientes (VENTA) o Proveedores (COMPRA)
     *     @var int         $cuenta_resultado_id          Cuenta Ingresos (VENTA) o Gasto/Activo (COMPRA)
     *     @var int|null    $cuenta_iva_id                Cuenta IVA Débito o IVA Crédito (obligatoria si monto_iva > 0)
     *     @var int|null    $cuenta_impuesto_adicional_id Cuenta Impuestos Adicionales (obligatoria si monto_impuesto_adicional > 0)
     *     @var int         $usuario_id                   ID del Contador que registra
     * }
     * @return array{documento_id: int, asiento_id: int, desglose: array<string, string>}
     * @throws Exception Si falla alguna validación tributaria, contable o de unicidad.
     */
    public function registrarDocumento(int $empresaId, int $periodoId, array $datos): array
    {
        $this->validarEntradaBase($datos);

        $tipoOperacion   = strtoupper(trim((string)$datos['tipo_operacion']));
        $tipoDte         = (int)$datos['tipo_documento_sii'];
        $folio           = (int)$datos['folio'];
        $rutContraparte  = $this->sanitizarYValidarRut((string)$datos['rut_contraparte']);
        $razonSocial     = trim((string)$datos['razon_social_contraparte']);
        $fechaEmision    = trim((string)$datos['fecha_emision']);
        $fechaRecepcion  = isset($datos['fecha_recepcion_sii']) && trim((string)$datos['fecha_recepcion_sii']) !== ''
            ? trim((string)$datos['fecha_recepcion_sii'])
            : null;
        $glosa           = trim((string)$datos['glosa']);
        $usuarioId       = (int)$datos['usuario_id'];

        if (!in_array($tipoOperacion, self::OPERACIONES_VALIDAS, true)) {
            throw new Exception("Tipo de operación inválido: '{$tipoOperacion}'. Debe ser 'COMPRA' o 'VENTA'.");
        }

        if (!in_array($tipoDte, self::TIPOS_DTE_VALIDOS, true)) {
            throw new Exception("Tipo de documento SII no soportado: '{$tipoDte}'.");
        }

        if ($folio <= 0) {
            throw new Exception('El folio del documento debe ser mayor a cero.');
        }

        if (in_array($tipoDte, [39, 41], true) && $tipoOperacion === 'COMPRA') {
            throw new Exception("Las boletas electrónicas (DTE {$tipoDte}) solo pueden registrarse en el Libro de Ventas.");
        }

        // 1. Fail-Fast: Verificar unicidad documental antes de iniciar transacción
        if ($this->existeDocumentoDuplicado($empresaId, $tipoOperacion, $tipoDte, $rutContraparte, $folio)) {
            throw new Exception(
                "El DTE tipo {$tipoDte} folio N° {$folio} para el RUT {$rutContraparte} ya se encuentra registrado en {$tipoOperacion}."
            );
        }

        // 2. Calcular desglose monetario exacto en enteros CLP y formatear a 4 decimales
        $desglose = $this->calcularDesgloseTributario($tipoOperacion, $tipoDte, $datos);

        $exentoDecim     = bcadd($desglose['monto_exento'], '0', 4);
        $netoDecim       = bcadd($desglose['monto_neto'], '0', 4);
        $ivaDecim        = bcadd($desglose['monto_iva'], '0', 4);
        $ivaNoRecupDecim = bcadd($desglose['iva_no_recuperable'], '0', 4);
        $impAdicDecim    = bcadd($desglose['monto_impuesto_adicional'], '0', 4);
        $totalDecim      = bcadd($desglose['monto_total'], '0', 4);

        $codigoImpAdic = isset($datos['codigo_impuesto_adicional']) && trim((string)$datos['codigo_impuesto_adicional']) !== ''
            ? strtoupper(mb_substr(trim((string)$datos['codigo_impuesto_adicional']), 0, 10))
            : null;

        // 3. Construir matriz contable en partida doble (respetando sentido de Nota de Crédito 61)
        $lineasAsiento = $this->construirLineasAsiento(
            $tipoOperacion,
            $tipoDte,
            $desglose,
            $datos
        );

        // 4. Bloque Transaccional ACID Global
        $transaccionPropia = !$this->pdo->inTransaction();
        if ($transaccionPropia) {
            $this->pdo->beginTransaction();
        }

        try {
            // A. Insertar en `libro_compras_ventas`
            $sqlDoc = "INSERT INTO libro_compras_ventas (
                            empresa_id, periodo_id, tipo_operacion, tipo_documento_sii, folio,
                            rut_contraparte, razon_social_contraparte, fecha_emision, fecha_recepcion_sii,
                            monto_exento, monto_neto, tasa_iva, monto_iva, iva_no_recuperable,
                            codigo_impuesto_adicional, monto_impuesto_adicional, monto_total,
                            glosa, estado, creado_por, creado_en
                        ) VALUES (
                            :empresa_id, :periodo_id, :tipo_operacion, :tipo_documento_sii, :folio,
                            :rut_contraparte, :razon_social_contraparte, :fecha_emision, :fecha_recepcion_sii,
                            :monto_exento, :monto_neto, :tasa_iva, :monto_iva, :iva_no_recuperable,
                            :codigo_impuesto_adicional, :monto_impuesto_adicional, :monto_total,
                            :glosa, 'VIGENTE', :creado_por, NOW()
                        )";

            $stmtDoc = $this->pdo->prepare($sqlDoc);
            $stmtDoc->execute([
                ':empresa_id'                => $empresaId,
                ':periodo_id'                => $periodoId,
                ':tipo_operacion'            => $tipoOperacion,
                ':tipo_documento_sii'        => $tipoDte,
                ':folio'                     => $folio,
                ':rut_contraparte'           => $rutContraparte,
                ':razon_social_contraparte'  => mb_substr($razonSocial, 0, 200),
                ':fecha_emision'             => $fechaEmision,
                ':fecha_recepcion_sii'       => $fechaRecepcion,
                ':monto_exento'              => $exentoDecim,
                ':monto_neto'                => $netoDecim,
                ':tasa_iva'                  => self::TASA_IVA_DEFAULT,
                ':monto_iva'                 => $ivaDecim,
                ':iva_no_recuperable'        => $ivaNoRecupDecim,
                ':codigo_impuesto_adicional' => $codigoImpAdic,
                ':monto_impuesto_adicional'  => $impAdicDecim,
                ':monto_total'               => $totalDecim,
                ':glosa'                     => mb_substr($glosa, 0, 255),
                ':creado_por'                => $usuarioId,
            ]);

            $documentoId = (int)$this->pdo->lastInsertId();

            // B. Despachar asiento contable inmutable a AsientoService
            $etiquetaDte = $this->obtenerNombreCortoDte($tipoDte);
            $glosaAsiento = "{$tipoOperacion} {$etiquetaDte} N° {$folio} - {$razonSocial}";

            $asientoId = $this->asientoService->registrarAsiento(
                $empresaId,
                $periodoId,
                $fechaEmision,
                mb_substr($glosaAsiento, 0, 255),
                $lineasAsiento
            );

            // C. Vincular `asiento_id` generado en `libro_compras_ventas`
            $stmtLink = $this->pdo->prepare("
                UPDATE libro_compras_ventas
                SET asiento_id = :asiento_id
                WHERE id = :id AND empresa_id = :empresa_id
            ");
            $stmtLink->execute([
                ':asiento_id' => $asientoId,
                ':id'         => $documentoId,
                ':empresa_id' => $empresaId,
            ]);

            // D. Registrar trazabilidad inmutable mediante AuditoriaService
            $this->auditoriaService->registrarEvento(
                $empresaId,
                'INSERT',
                'libro_compras_ventas',
                $documentoId,
                [
                    'tipo_operacion'     => $tipoOperacion,
                    'tipo_documento_sii' => $tipoDte,
                    'folio'              => $folio,
                    'rut_contraparte'    => $rutContraparte,
                    'monto_neto'         => $desglose['monto_neto'],
                    'monto_exento'       => $desglose['monto_exento'],
                    'monto_iva'          => $desglose['monto_iva'],
                    'monto_total'        => $desglose['monto_total'],
                    'asiento_id'         => $asientoId,
                    'usuario_id'         => $usuarioId,
                ]
            );

            if ($transaccionPropia) {
                $this->pdo->commit();
            }

            return [
                'documento_id' => $documentoId,
                'asiento_id'   => $asientoId,
                'desglose'     => $desglose,
            ];
        } catch (Throwable $e) {
            if ($transaccionPropia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new Exception('Error al registrar documento en Libro de Compras/Ventas: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Anula administrativamente un documento del RCV generando su contraasiento
     * contable (inversión DEBE <-> HABER) dentro de una transacción ACID.
     *
     * @param int    $empresaId   ID de la empresa
     * @param int    $documentoId ID del documento en `libro_compras_ventas`
     * @param int    $usuarioId   ID del Contador que ejecuta la anulación
     * @param string $motivo      Motivo de anulación
     * @return array{documento_id: int, contraasiento_id: int}
     * @throws Exception
     */
    public function anularDocumento(int $empresaId, int $documentoId, int $usuarioId, string $motivo): array
    {
        $motivoLimpio = trim($motivo);
        if ($motivoLimpio === '') {
            throw new Exception('Debe especificar un motivo válido para anular el documento.');
        }

        $stmt = $this->pdo->prepare("
            SELECT id, periodo_id, tipo_operacion, tipo_documento_sii, folio,
                    rut_contraparte, razon_social_contraparte, fecha_emision, asiento_id, estado
            FROM libro_compras_ventas
            WHERE id = :id AND empresa_id = :empresa_id
            LIMIT 1
        ");
        $stmt->execute([
            ':id'         => $documentoId,
            ':empresa_id' => $empresaId,
        ]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            throw new Exception("El documento ID {$documentoId} no existe o no pertenece a la empresa.");
        }

        if ($doc['estado'] === 'ANULADO') {
            throw new Exception("El DTE tipo {$doc['tipo_documento_sii']} folio N° {$doc['folio']} ya se encuentra anulado.");
        }

        if (empty($doc['asiento_id'])) {
            throw new Exception("El documento folio N° {$doc['folio']} no posee un asiento contable asociado.");
        }

        $asientoOriginalId = (int)$doc['asiento_id'];
        $periodoId         = (int)$doc['periodo_id'];

        $transaccionPropia = !$this->pdo->inTransaction();
        if ($transaccionPropia) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmtDetalle = $this->pdo->prepare("
                SELECT cuenta_id, debe, haber
                FROM detalle_asientos
                WHERE asiento_id = :asiento_id
                ORDER BY id ASC
            ");
            $stmtDetalle->execute([':asiento_id' => $asientoOriginalId]);
            $lineasOriginales = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

            if (empty($lineasOriginales)) {
                throw new Exception("No se encontraron líneas contables para el asiento original ID {$asientoOriginalId}.");
            }

            $lineasContraasiento = [];
            foreach ($lineasOriginales as $linea) {
                $lineasContraasiento[] = [
                    'cuenta_id' => (int)$linea['cuenta_id'],
                    'debe'      => bcadd((string)$linea['haber'], '0', 4),
                    'haber'     => bcadd((string)$linea['debe'], '0', 4),
                ];
            }

            $glosaContra = "ANULACION {$doc['tipo_operacion']} DTE {$doc['tipo_documento_sii']} N° {$doc['folio']} - {$doc['razon_social_contraparte']} [Motivo: {$motivoLimpio}]";

            $contraasientoId = $this->asientoService->registrarAsiento(
                $empresaId,
                $periodoId,
                (string)$doc['fecha_emision'],
                mb_substr($glosaContra, 0, 255),
                $lineasContraasiento
            );

            $stmtUpdate = $this->pdo->prepare("
                UPDATE libro_compras_ventas
                SET estado = 'ANULADO',
                    actualizado_en = NOW()
                WHERE id = :id AND empresa_id = :empresa_id
            ");
            $stmtUpdate->execute([
                ':id'         => $documentoId,
                ':empresa_id' => $empresaId,
            ]);

            $this->auditoriaService->registrarEvento(
                $empresaId,
                'UPDATE_ANULACION',
                'libro_compras_ventas',
                $documentoId,
                [
                    'tipo_operacion'      => $doc['tipo_operacion'],
                    'tipo_documento_sii'  => $doc['tipo_documento_sii'],
                    'folio'               => $doc['folio'],
                    'asiento_original_id' => $asientoOriginalId,
                    'contraasiento_id'    => $contraasientoId,
                    'motivo'              => $motivoLimpio,
                    'usuario_id'          => $usuarioId,
                ]
            );

            if ($transaccionPropia) {
                $this->pdo->commit();
            }

            return [
                'documento_id'     => $documentoId,
                'contraasiento_id' => $contraasientoId,
            ];
        } catch (Throwable $e) {
            if ($transaccionPropia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new Exception('Error al anular documento en Libro de Compras/Ventas: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Lista los documentos vigentes o anulados del RCV para una empresa y período.
     *
     * @param int         $empresaId
     * @param int         $periodoId
     * @param string|null $tipoOperacion 'COMPRA', 'VENTA' o null para ambos
     * @return array<int, array<string, mixed>>
     */
    public function listarDocumentosPeriodo(int $empresaId, int $periodoId, ?string $tipoOperacion = null): array
    {
        $sql = "SELECT id, tipo_operacion, tipo_documento_sii, folio, rut_contraparte,
                        razon_social_contraparte, fecha_emision, fecha_recepcion_sii,
                        monto_exento, monto_neto, monto_iva, iva_no_recuperable,
                        codigo_impuesto_adicional, monto_impuesto_adicional, monto_total,
                        glosa, estado, asiento_id, creado_en
                FROM libro_compras_ventas
                WHERE empresa_id = :empresa_id AND periodo_id = :periodo_id";

        $params = [
            ':empresa_id' => $empresaId,
            ':periodo_id' => $periodoId,
        ];

        if ($tipoOperacion !== null && in_array(strtoupper($tipoOperacion), self::OPERACIONES_VALIDAS, true)) {
            $sql .= " AND tipo_operacion = :tipo_operacion";
            $params[':tipo_operacion'] = strtoupper($tipoOperacion);
        }

        $sql .= " ORDER BY fecha_emision ASC, id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Calcula el resumen tributario mensual (Base para Formulario 29 SII),
     * restando automáticamente las Notas de Crédito (DTE 61) de los documentos vigentes.
     *
     * @param int $empresaId
     * @param int $periodoId
     * @return array<string, string>
     */
    public function obtenerResumenF29Periodo(int $empresaId, int $periodoId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT tipo_operacion, tipo_documento_sii,
                    monto_exento, monto_neto, monto_iva, iva_no_recuperable,
                    monto_impuesto_adicional, monto_total
            FROM libro_compras_ventas
            WHERE empresa_id = :empresa_id
                AND periodo_id = :periodo_id
                AND estado = 'VIGENTE'
        ");
        $stmt->execute([
            ':empresa_id' => $empresaId,
            ':periodo_id' => $periodoId,
        ]);

        $totales = [
            'ventas_exentas'             => '0',
            'ventas_netas'               => '0',
            'iva_debito_fiscal'          => '0',
            'ventas_totales'             => '0',
            'compras_exentas'            => '0',
            'compras_netas'              => '0',
            'iva_credito_fiscal'         => '0',
            'iva_no_recuperable'         => '0',
            'impuestos_adicionales_comp' => '0',
            'compras_totales'            => '0',
            'iva_determinado_f29'        => '0', // Positivo = IVA a pagar; Negativo = Remanente crédito
        ];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $esNotaCredito = ((int)$row['tipo_documento_sii'] === 61);
            $op = $esNotaCredito ? 'bcsub' : 'bcadd';

            $exento     = bcadd((string)$row['monto_exento'], '0', 0);
            $neto       = bcadd((string)$row['monto_neto'], '0', 0);
            $iva        = bcadd((string)$row['monto_iva'], '0', 0);
            $ivaNoRecup = bcadd((string)$row['iva_no_recuperable'], '0', 0);
            $impAdic    = bcadd((string)$row['monto_impuesto_adicional'], '0', 0);
            $total      = bcadd((string)$row['monto_total'], '0', 0);

            if ($row['tipo_operacion'] === 'VENTA') {
                $totales['ventas_exentas']    = $op($totales['ventas_exentas'], $exento, 0);
                $totales['ventas_netas']      = $op($totales['ventas_netas'], $neto, 0);
                $totales['iva_debito_fiscal'] = $op($totales['iva_debito_fiscal'], $iva, 0);
                $totales['ventas_totales']    = $op($totales['ventas_totales'], $total, 0);
            } else {
                $totales['compras_exentas']            = $op($totales['compras_exentas'], $exento, 0);
                $totales['compras_netas']              = $op($totales['compras_netas'], $neto, 0);
                $totales['iva_credito_fiscal']         = $op($totales['iva_credito_fiscal'], $iva, 0);
                $totales['iva_no_recuperable']         = $op($totales['iva_no_recuperable'], $ivaNoRecup, 0);
                $totales['impuestos_adicionales_comp'] = $op($totales['impuestos_adicionales_comp'], $impAdic, 0);
                $totales['compras_totales']            = $op($totales['compras_totales'], $total, 0);
            }
        }

        $totales['iva_determinado_f29'] = bcsub(
            $totales['iva_debito_fiscal'],
            $totales['iva_credito_fiscal'],
            0
        );

        return $totales;
    }

    /**
     * Calcula y cuadra los montos tributarios en pesos enteros CLP según modo de ingreso.
     *
     * @param string $tipoOperacion
     * @param int    $tipoDte
     * @param array  $datos
     * @return array<string, string>
     * @throws Exception
     */
    private function calcularDesgloseTributario(string $tipoOperacion, int $tipoDte, array $datos): array
    {
        $modo = strtoupper(trim((string)($datos['modo_ingreso'] ?? 'NETO')));

        $montoExento  = $this->parsearEnteroNoNegativo((string)($datos['monto_exento'] ?? '0'), 'monto_exento');
        $ivaNoRecup   = $this->parsearEnteroNoNegativo((string)($datos['iva_no_recuperable'] ?? '0'), 'iva_no_recuperable');
        $impAdicional = $this->parsearEnteroNoNegativo((string)($datos['monto_impuesto_adicional'] ?? '0'), 'monto_impuesto_adicional');

        if ($tipoOperacion === 'VENTA' && bccomp($ivaNoRecup, '0', 0) > 0) {
            throw new Exception('El IVA no recuperable solo aplica a documentos de COMPRA.');
        }

        // Documentos estrictamente exentos (DTE 34 y 41)
        if (in_array($tipoDte, self::DTE_EXENTOS, true)) {
            $valorExento = $montoExento;
            if (bccomp($valorExento, '0', 0) === 0 && isset($datos['monto_ingresado'])) {
                $valorExento = $this->parsearEnteroNoNegativo((string)$datos['monto_ingresado'], 'monto_ingresado');
            }

            if (bccomp($valorExento, '0', 0) <= 0) {
                throw new Exception("Un documento exento (DTE {$tipoDte}) requiere un monto exento mayor a cero.");
            }

            $total = bcadd($valorExento, $impAdicional, 0);

            return [
                'monto_exento'             => $valorExento,
                'monto_neto'               => '0',
                'monto_iva'                => '0',
                'iva_no_recuperable'       => '0',
                'monto_impuesto_adicional' => $impAdicional,
                'monto_total'              => $total,
            ];
        }

        if ($modo === 'BRUTO') {
            $brutoAfecto = $this->parsearEnteroNoNegativo((string)($datos['monto_ingresado'] ?? '0'), 'monto_ingresado');
            if (bccomp($brutoAfecto, '0', 0) <= 0) {
                throw new Exception('El monto bruto afecto debe ser mayor a cero.');
            }

            $resultadoIva = CalculadoraImpuestosService::desglosarBruto($brutoAfecto);
            $montoNeto    = $resultadoIva['neto'];
            $ivaBruto     = $resultadoIva['iva'];

            if (bccomp($ivaNoRecup, $ivaBruto, 0) > 0) {
                throw new Exception('El IVA no recuperable no puede superar el IVA total del documento.');
            }

            $ivaRecuperable = bcsub($ivaBruto, $ivaNoRecup, 0);
        } elseif ($modo === 'NETO') {
            $montoNeto = $this->parsearEnteroNoNegativo((string)($datos['monto_ingresado'] ?? '0'), 'monto_ingresado');
            if (bccomp($montoNeto, '0', 0) <= 0 && bccomp($montoExento, '0', 0) <= 0) {
                throw new Exception('Debe ingresar un monto neto o exento superior a cero.');
            }

            $ivaCalculado = $this->bcround(bcmul($montoNeto, self::TASA_IVA_DEFAULT, 4), 0);

            if (bccomp($ivaNoRecup, $ivaCalculado, 0) > 0) {
                throw new Exception('El IVA no recuperable no puede superar el 19% calculado sobre el neto.');
            }

            $ivaRecuperable = bcsub($ivaCalculado, $ivaNoRecup, 0);
        } elseif ($modo === 'DESGLOSADO') {
            $montoNeto = $this->parsearEnteroNoNegativo((string)($datos['monto_neto'] ?? '0'), 'monto_neto');
            $ivaRecuperable = isset($datos['monto_iva']) && trim((string)$datos['monto_iva']) !== ''
                ? $this->parsearEnteroNoNegativo((string)$datos['monto_iva'], 'monto_iva')
                : $this->bcround(bcmul($montoNeto, self::TASA_IVA_DEFAULT, 4), 0);
        } else {
            throw new Exception("Modo de ingreso inválido: '{$modo}'. Use 'BRUTO', 'NETO' o 'DESGLOSADO'.");
        }

        // Cuadratura exacta idéntica a `chk_rcv_total` en MySQL
        $montoTotal = bcadd($montoExento, $montoNeto, 0);
        $montoTotal = bcadd($montoTotal, $ivaRecuperable, 0);
        $montoTotal = bcadd($montoTotal, $ivaNoRecup, 0);
        $montoTotal = bcadd($montoTotal, $impAdicional, 0);

        if (bccomp($montoTotal, '0', 0) <= 0) {
            throw new Exception('El monto total del documento debe ser mayor a cero.');
        }

        return [
            'monto_exento'             => $montoExento,
            'monto_neto'               => $montoNeto,
            'monto_iva'                => $ivaRecuperable,
            'iva_no_recuperable'       => $ivaNoRecup,
            'monto_impuesto_adicional' => $impAdicional,
            'monto_total'              => $montoTotal,
        ];
    }

    /**
     * Construye las líneas unidireccionales (DEBE / HABER) según tipo de operación y DTE.
     *
     * @param string $tipoOperacion
     * @param int    $tipoDte
     * @param array  $desglose
     * @param array  $datos
     * @return array<int, array{cuenta_id: int, debe: string, haber: string}>
     * @throws Exception
     */
    private function construirLineasAsiento(
        string $tipoOperacion,
        int $tipoDte,
        array $desglose,
        array $datos
    ): array {
        $cuentaContraparteId = (int)$datos['cuenta_contraparte_id'];
        $cuentaResultadoId   = (int)$datos['cuenta_resultado_id'];
        $cuentaIvaId         = isset($datos['cuenta_iva_id']) ? (int)$datos['cuenta_iva_id'] : 0;
        $cuentaImpAdicId     = isset($datos['cuenta_impuesto_adicional_id']) ? (int)$datos['cuenta_impuesto_adicional_id'] : 0;

        $ivaEntero     = $desglose['monto_iva'];
        $impAdicEntero = $desglose['monto_impuesto_adicional'];
        $totalEntero   = $desglose['monto_total'];

        if (bccomp($ivaEntero, '0', 0) > 0 && $cuentaIvaId <= 0) {
            throw new Exception('Debe seleccionar la cuenta contable de IVA (Crédito o Débito Fiscal).');
        }

        if (bccomp($impAdicEntero, '0', 0) > 0 && $cuentaImpAdicId <= 0) {
            throw new Exception('Debe seleccionar la cuenta contable para Impuestos Adicionales.');
        }

        // En Compras, el IVA no recuperable forma parte del costo/gasto contable
        $baseResultado = bcadd($desglose['monto_neto'], $desglose['monto_exento'], 0);
        if ($tipoOperacion === 'COMPRA') {
            $baseResultado = bcadd($baseResultado, $desglose['iva_no_recuperable'], 0);
        }

        $esNotaCredito = ($tipoDte === 61);
        $lineas = [];

        if ($tipoOperacion === 'VENTA') {
            $lineas[] = [
                'cuenta_id' => $cuentaContraparteId,
                'debe'      => $esNotaCredito ? '0.0000' : bcadd($totalEntero, '0', 4),
                'haber'     => $esNotaCredito ? bcadd($totalEntero, '0', 4) : '0.0000',
            ];

            if (bccomp($baseResultado, '0', 0) > 0) {
                $lineas[] = [
                    'cuenta_id' => $cuentaResultadoId,
                    'debe'      => $esNotaCredito ? bcadd($baseResultado, '0', 4) : '0.0000',
                    'haber'     => $esNotaCredito ? '0.0000' : bcadd($baseResultado, '0', 4),
                ];
            }

            if (bccomp($ivaEntero, '0', 0) > 0) {
                $lineas[] = [
                    'cuenta_id' => $cuentaIvaId,
                    'debe'      => $esNotaCredito ? bcadd($ivaEntero, '0', 4) : '0.0000',
                    'haber'     => $esNotaCredito ? '0.0000' : bcadd($ivaEntero, '0', 4),
                ];
            }

            if (bccomp($impAdicEntero, '0', 0) > 0) {
                $lineas[] = [
                    'cuenta_id' => $cuentaImpAdicId,
                    'debe'      => $esNotaCredito ? bcadd($impAdicEntero, '0', 4) : '0.0000',
                    'haber'     => $esNotaCredito ? '0.0000' : bcadd($impAdicEntero, '0', 4),
                ];
            }
        } else {
            if (bccomp($baseResultado, '0', 0) > 0) {
                $lineas[] = [
                    'cuenta_id' => $cuentaResultadoId,
                    'debe'      => $esNotaCredito ? '0.0000' : bcadd($baseResultado, '0', 4),
                    'haber'     => $esNotaCredito ? bcadd($baseResultado, '0', 4) : '0.0000',
                ];
            }

            if (bccomp($ivaEntero, '0', 0) > 0) {
                $lineas[] = [
                    'cuenta_id' => $cuentaIvaId,
                    'debe'      => $esNotaCredito ? '0.0000' : bcadd($ivaEntero, '0', 4),
                    'haber'     => $esNotaCredito ? bcadd($ivaEntero, '0', 4) : '0.0000',
                ];
            }

            if (bccomp($impAdicEntero, '0', 0) > 0) {
                $lineas[] = [
                    'cuenta_id' => $cuentaImpAdicId,
                    'debe'      => $esNotaCredito ? '0.0000' : bcadd($impAdicEntero, '0', 4),
                    'haber'     => $esNotaCredito ? bcadd($impAdicEntero, '0', 4) : '0.0000',
                ];
            }

            $lineas[] = [
                'cuenta_id' => $cuentaContraparteId,
                'debe'      => $esNotaCredito ? bcadd($totalEntero, '0', 4) : '0.0000',
                'haber'     => $esNotaCredito ? '0.0000' : bcadd($totalEntero, '0', 4),
            ];
        }

        return $lineas;
    }

    private function existeDocumentoDuplicado(
        int $empresaId,
        string $tipoOperacion,
        int $tipoDte,
        string $rutContraparte,
        int $folio
    ): bool {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM libro_compras_ventas
            WHERE empresa_id = :empresa_id
                AND tipo_operacion = :tipo_operacion
                AND tipo_documento_sii = :tipo_documento_sii
                AND rut_contraparte = :rut_contraparte
                AND folio = :folio
        ");
        $stmt->execute([
            ':empresa_id'         => $empresaId,
            ':tipo_operacion'     => $tipoOperacion,
            ':tipo_documento_sii' => $tipoDte,
            ':rut_contraparte'    => $rutContraparte,
            ':folio'              => $folio,
        ]);

        return ((int)$stmt->fetchColumn()) > 0;
    }

    private function validarEntradaBase(array $datos): void
    {
        $requeridos = [
            'tipo_operacion',
            'tipo_documento_sii',
            'folio',
            'rut_contraparte',
            'razon_social_contraparte',
            'fecha_emision',
            'glosa',
            'cuenta_contraparte_id',
            'cuenta_resultado_id',
            'usuario_id',
        ];

        foreach ($requeridos as $campo) {
            if (!isset($datos[$campo]) || trim((string)$datos[$campo]) === '') {
                throw new Exception("El parámetro '{$campo}' es obligatorio para registrar el documento.");
            }
        }
    }

    private function parsearEnteroNoNegativo(string $valorRaw, string $nombreCampo): string
    {
        $limpio = trim($valorRaw);
        if ($limpio === '') {
            return '0';
        }
        if (!is_numeric($limpio)) {
            throw new Exception("El campo '{$nombreCampo}' debe ser un valor numérico válido.");
        }
        $entero = bcadd($limpio, '0', 0);
        if (bccomp($entero, '0', 0) < 0) {
            throw new Exception("El campo '{$nombreCampo}' no puede ser negativo.");
        }
        return $entero;
    }

    private function sanitizarYValidarRut(string $rut): string
    {
        $rutLimpio = preg_replace('/[^0-9kK]/', '', trim($rut));

        if (strlen($rutLimpio) < 7 || strlen($rutLimpio) > 9) {
            throw new Exception("El RUT ingresado ('{$rut}') no es válido.");
        }

        $cuerpo = substr($rutLimpio, 0, -1);
        $dv     = strtoupper(substr($rutLimpio, -1));

        if (!ctype_digit($cuerpo)) {
            throw new Exception("El cuerpo del RUT '{$rut}' contiene caracteres no numéricos no válidos.");
        }

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

    private function obtenerNombreCortoDte(int $tipoDte): string
    {
        return match ($tipoDte) {
            33      => 'FAC-EL',
            34      => 'FAC-EX',
            39      => 'BOL-EL',
            41      => 'BOL-EX',
            46      => 'FAC-COMP',
            56      => 'N-DEB',
            61      => 'N-CRED',
            default => "DTE-{$tipoDte}",
        };
    }

    private function bcround(string $number, int $scale = 0): string
    {
        if (strpos($number, '.') === false) {
            return bcadd($number, '0', $scale);
        }

        $offset = '0.' . str_repeat('0', $scale) . '5';

        if (bccomp($number, '0', 4) >= 0) {
            return bcadd($number, $offset, $scale);
        }

        return bcsub($number, $offset, $scale);
    }
}