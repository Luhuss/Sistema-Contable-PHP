<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Auditoria/AuditoriaService.php';
require_once __DIR__ . '/../Contabilidad/AsientoService.php';
require_once __DIR__ . '/../Tributario/LibroComprasVentasService.php';

$pdo = Database::getConnection();

function verificar(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new RuntimeException("FALLO DE VERIFICACION: {$mensaje}");
    }
    echo "[OK] {$mensaje}\n";
}

// Iniciamos transacción maestra para revertir todo al terminar la prueba
$pdo->beginTransaction();

try {
    echo "=== INICIANDO AUDITORIA TECNICA DE LIBRO DE COMPRAS Y VENTAS ===\n\n";

    // 1. Crear fixtures mínimos aislados en memoria transaccional
    $pdo->exec("
        INSERT INTO empresas (rut, razon_social, giro, regimen_tributario, tasa_ppm, activa)
        VALUES ('76999888-5', 'EMPRESA TEST RCV SPA', 'SERVICIOS INFORMATICOS', 'PRO_PYME_GENERAL', 0.0025, 1)
    ");
    $empresaId = (int)$pdo->lastInsertId();

    $pdo->exec("
        INSERT INTO usuarios (rut, nombre_completo, email, password_hash, rol, activo)
        VALUES ('11111111-1', 'Contador Auditor', 'test_rcv@sistema.cl', 'hash_dummy', 'CONTADOR', 1)
    ");
    $usuarioId = (int)$pdo->lastInsertId();

    $pdo->exec("
        INSERT INTO periodos_fiscales (empresa_id, anio, mes, estado)
        VALUES ({$empresaId}, 2026, 11, 'ABIERTO')
    ");
    $periodoId = (int)$pdo->lastInsertId();

    // Crear cuentas imputables necesarias para las pruebas
    $cuentas = [
        ['1.1.02.001', 'Clientes Nacionales', 'ACTIVO', 'DEUDORA'],
        ['1.1.05.001', 'IVA Credito Fiscal', 'ACTIVO', 'DEUDORA'],
        ['2.1.01.001', 'Proveedores Nacionales', 'PASIVO', 'ACREEDORA'],
        ['2.1.05.001', 'IVA Debito Fiscal', 'PASIVO', 'ACREEDORA'],
        ['4.1.01.001', 'Ventas del Giro', 'INGRESO', 'ACREEDORA'],
        ['5.1.01.001', 'Gastos Generales y Costos', 'GASTO', 'DEUDORA'],
        ['5.1.02.001', 'Impuestos Especificos No Recuperables', 'GASTO', 'DEUDORA'],
    ];

    $ctaIds = [];
    $stmtCta = $pdo->prepare("
        INSERT INTO plan_cuentas (empresa_id, codigo, nombre, tipo, naturaleza, nivel, es_imputable, activa)
        VALUES (:emp, :cod, :nom, :tipo, :nat, 4, 1, 1)
    ");
    foreach ($cuentas as [$cod, $nom, $tipo, $nat]) {
        $stmtCta->execute([
            ':emp'  => $empresaId,
            ':cod'  => $cod,
            ':nom'  => $nom,
            ':tipo' => $tipo,
            ':nat'  => $nat,
        ]);
        $ctaIds[$cod] = (int)$pdo->lastInsertId();
    }

    // Instanciar servicios reales
    $auditoriaService = new AuditoriaService($pdo);
    $asientoService   = new AsientoService($pdo, $auditoriaService);
    $rcvService       = new LibroComprasVentasService($pdo, $asientoService, $auditoriaService);

    // ------------------------------------------------------------------------
    // PRUEBA 1: Venta Afecta DTE 33 en modo BRUTO ($119.000 -> $100.000 + $19.000)
    // ------------------------------------------------------------------------
    $resVenta = $rcvService->registrarDocumento($empresaId, $periodoId, [
        'tipo_operacion'           => 'VENTA',
        'tipo_documento_sii'       => 33,
        'folio'                    => 1001,
        'rut_contraparte'          => '76.123.456-0',
        'razon_social_contraparte' => 'CLIENTE PRUEBA LTDA',
        'fecha_emision'            => '2026-11-10',
        'modo_ingreso'             => 'BRUTO',
        'monto_ingresado'          => '119000',
        'glosa'                    => 'Venta servicios consultoria',
        'cuenta_contraparte_id'    => $ctaIds['1.1.02.001'],
        'cuenta_resultado_id'      => $ctaIds['4.1.01.001'],
        'cuenta_iva_id'            => $ctaIds['2.1.05.001'],
        'usuario_id'               => $usuarioId,
    ]);

    verificar($resVenta['desglose']['monto_neto'] === '100000', 'Venta Bruto $119.000 desglosa Neto = $100.000');
    verificar($resVenta['desglose']['monto_iva'] === '19000', 'Venta Bruto $119.000 desglosa IVA = $19.000');

    // ------------------------------------------------------------------------
    // PRUEBA 2: Nota de Crédito Venta DTE 61 (Debe restar en F29)
    // ------------------------------------------------------------------------
    $rcvService->registrarDocumento($empresaId, $periodoId, [
        'tipo_operacion'           => 'VENTA',
        'tipo_documento_sii'       => 61,
        'folio'                    => 55,
        'rut_contraparte'          => '76123456-0',
        'razon_social_contraparte' => 'CLIENTE PRUEBA LTDA',
        'fecha_emision'            => '2026-11-12',
        'modo_ingreso'             => 'NETO',
        'monto_ingresado'          => '20000',
        'glosa'                    => 'Descuento comercial sobre factura 1001',
        'cuenta_contraparte_id'    => $ctaIds['1.1.02.001'],
        'cuenta_resultado_id'      => $ctaIds['4.1.01.001'],
        'cuenta_iva_id'            => $ctaIds['2.1.05.001'],
        'usuario_id'               => $usuarioId,
    ]);

    // ------------------------------------------------------------------------
    // PRUEBA 3: Compra con IVA No Recuperable e Impuesto Adicional (Combustible)
    // ------------------------------------------------------------------------
    $resCompra = $rcvService->registrarDocumento($empresaId, $periodoId, [
        'tipo_operacion'               => 'COMPRA',
        'tipo_documento_sii'           => 33,
        'folio'                        => 8899,
        'rut_contraparte'              => '96.888.777-7',
        'razon_social_contraparte'     => 'DISTRIBUIDORA COMBUSTIBLES SA',
        'fecha_emision'                => '2026-11-13',
        'modo_ingreso'                 => 'DESGLOSADO',
        'monto_neto'                   => '50000',
        'monto_iva'                    => '5000',
        'iva_no_recuperable'           => '4500',
        'codigo_impuesto_adicional'    => 'COMB',
        'monto_impuesto_adicional'     => '3000',
        'glosa'                        => 'Carga petroleo flota',
        'cuenta_contraparte_id'        => $ctaIds['2.1.01.001'],
        'cuenta_resultado_id'          => $ctaIds['5.1.01.001'],
        'cuenta_iva_id'                => $ctaIds['1.1.05.001'],
        'cuenta_impuesto_adicional_id' => $ctaIds['5.1.02.001'],
        'usuario_id'                   => $usuarioId,
    ]);

    verificar($resCompra['desglose']['monto_total'] === '62500', 'Compra compleja cuadra total exacto = $62.500');

    // Verificar que el gasto contable absorbió Neto ($50.000) + IVA No Recuperable ($4.500) = $54.500
    $stmtLineaGasto = $pdo->prepare("
        SELECT debe FROM detalle_asientos
        WHERE asiento_id = :asiento_id AND cuenta_id = :cta_id
    ");
    $stmtLineaGasto->execute([
        ':asiento_id' => $resCompra['asiento_id'],
        ':cta_id'     => $ctaIds['5.1.01.001'],
    ]);
    $debeGasto = bcadd((string)$stmtLineaGasto->fetchColumn(), '0', 0);
    verificar($debeGasto === '54500', 'Asiento de compra suma IVA no recuperable al gasto ($54.500)');

    // ------------------------------------------------------------------------
    // PRUEBA 4: Resumen F29 (Débito $19.000 - NC $3.800 = $15.200; Crédito = $5.000 -> Pagar $10.200)
    // ------------------------------------------------------------------------
    $f29 = $rcvService->obtenerResumenF29Periodo($empresaId, $periodoId);
    verificar($f29['iva_debito_fiscal'] === '15200', 'F29 IVA Debito neto de Nota de Credito = $15.200');
    verificar($f29['iva_credito_fiscal'] === '5000', 'F29 IVA Credito Recuperable = $5.000');
    verificar($f29['iva_determinado_f29'] === '10200', 'F29 IVA Determinado a pagar = $10.200');

    // ------------------------------------------------------------------------
    // PRUEBA 5: Bloqueo de Unicidad Documental
    // ------------------------------------------------------------------------
    $duplicadoBloqueado = false;
    try {
        $rcvService->registrarDocumento($empresaId, $periodoId, [
            'tipo_operacion'           => 'VENTA',
            'tipo_documento_sii'       => 33,
            'folio'                    => 1001,
            'rut_contraparte'          => '76123456-0',
            'razon_social_contraparte' => 'CLIENTE PRUEBA LTDA',
            'fecha_emision'            => '2026-11-10',
            'modo_ingreso'             => 'BRUTO',
            'monto_ingresado'          => '119000',
            'glosa'                    => 'Intento duplicado',
            'cuenta_contraparte_id'    => $ctaIds['1.1.02.001'],
            'cuenta_resultado_id'      => $ctaIds['4.1.01.001'],
            'cuenta_iva_id'            => $ctaIds['2.1.05.001'],
            'usuario_id'               => $usuarioId,
        ]);
    } catch (Exception $e) {
        $duplicadoBloqueado = true;
    }
    verificar($duplicadoBloqueado, 'Bloqueo de folio duplicado por empresa/RUT/DTE operativo');

    // ------------------------------------------------------------------------
    // PRUEBA 6: Trigger ADR-002 (Prohibición de alterar montos en SQL directo)
    // ------------------------------------------------------------------------
    $triggerActivo = false;
    try {
        $pdo->exec("UPDATE libro_compras_ventas SET monto_total = 999999 WHERE id = {$resVenta['documento_id']}");
    } catch (PDOException $e) {
        $triggerActivo = str_contains($e->getMessage(), 'ADR-002');
    }
    verificar($triggerActivo, 'Trigger MySQL trg_rcv_inmutabilidad_update bloquea mutacion de montos');

    echo "\n=== TODAS LAS VERIFICACIONES SUPERADAS EXITOSAMENTE ===\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
        echo "(Rollback ejecutado: base de datos limpia sin datos de prueba)\n";
    }
}