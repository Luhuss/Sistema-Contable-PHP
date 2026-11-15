<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script de pruebas debe ejecutarse únicamente desde la terminal (CLI).\n");
}

// 1. Bootstrap desde config/app.php y config/database.php en la raíz
require_once __DIR__ . '/../config/app.php';
$dbReturn = require_once __DIR__ . '/../config/database.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    if ($dbReturn instanceof PDO) {
        $pdo = $dbReturn;
    } elseif (class_exists('Database') && method_exists('Database', 'getConnection')) {
        $pdo = Database::getConnection();
    } elseif (class_exists('Database') && method_exists('Database', 'conectar')) {
        $pdo = Database::conectar();
    } else {
        fwrite(STDERR, "[FATAL] No se pudo obtener una instancia PDO desde config/database.php\n");
        exit(1);
    }
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 2. Carga de Servicios del Dominio
require_once __DIR__ . '/../src/Auditoria/AuditoriaService.php';
require_once __DIR__ . '/../src/Empresas/EmpresaService.php';
require_once __DIR__ . '/../src/Cuentas/PlanCuentasService.php';
require_once __DIR__ . '/../src/Contabilidad/AsientoService.php';
require_once __DIR__ . '/../src/Tributario/LibroComprasVentasService.php';

$rutaCalcBhe = __DIR__ . '/../src/Tributario/CalculadoraHonorariosService.php';
if (file_exists($rutaCalcBhe)) {
    require_once $rutaCalcBhe;
}
require_once __DIR__ . '/../src/Tributario/LibroHonorariosService.php';
require_once __DIR__ . '/../src/Tributario/ServicioAnulacionHonorariosService.php';
require_once __DIR__ . '/../src/Reportes/ReportesServices.php';

$rutaActivoFijo  = __DIR__ . '/../src/ActivoFijo/ActivoFijoService.php';
$tieneActivoFijo = file_exists($rutaActivoFijo);
if ($tieneActivoFijo) {
    require_once $rutaActivoFijo;
}

// -------------------------------------------------------------------------
// Utilidades de Aserción y Generación de RUT Módulo 11
// -------------------------------------------------------------------------
$pruebasPasadas     = 0;
$pruebasFallidas    = 0;
$empresasCreadasIds = [];

function afirmar(bool $condicion, string $descripcion): void
{
    global $pruebasPasadas, $pruebasFallidas;
    if ($condicion) {
        $pruebasPasadas++;
        echo "  [OK] {$descripcion}\n";
    } else {
        $pruebasFallidas++;
        echo "  [FALLO] {$descripcion}\n";
        throw new RuntimeException("Aserción fallida: {$descripcion}");
    }
}

function generarRutValido(int $cuerpo): string
{
    $suma = 0;
    $mult = 2;
    $num  = $cuerpo;

    while ($num > 0) {
        $suma += ($num % 10) * $mult;
        $num   = intdiv($num, 10);
        $mult  = ($mult === 7) ? 2 : ($mult + 1);
    }

    $resto = 11 - ($suma % 11);
    $dv = match ($resto) {
        11      => '0',
        10      => 'K',
        default => (string)$resto,
    };

    return number_format($cuerpo, 0, '', '.') . '-' . $dv;
}

echo "=================================================================\n";
echo " SUITE DE PRUEBAS INTEGRALES - SISTEMA CONTABLE CHILE (SII 2026) \n";
echo "=================================================================\n\n";

$auditoriaService   = new AuditoriaService($pdo);
$maxLicencia        = defined('LICENCIA_MAX_EMPRESAS') ? (int)LICENCIA_MAX_EMPRESAS : 5;
$empresaService     = new EmpresaService($pdo, $auditoriaService, max($maxLicencia + 5, 10));
$planCuentasService = new PlanCuentasService($pdo, $auditoriaService);
$asientoService     = new AsientoService($pdo);
$rcvService         = new LibroComprasVentasService($pdo, $asientoService, $auditoriaService);
$honorariosService  = new LibroHonorariosService($pdo, $asientoService);
$anulacionBheSrv    = new ServicioAnulacionHonorariosService($pdo, $asientoService);
$reportesService    = new ReportesService($pdo);

try {
    // ---------------------------------------------------------------------
    // BLOQUE 1: Infraestructura, BCMath y Usuario Contador
    // ---------------------------------------------------------------------
    echo "1. Verificando infraestructura base (PDO + BCMath + Usuario)...\n";
    afirmar(extension_loaded('bcmath'), 'Extensión PHP BCMath habilitada para aritmética exacta CLP');

    $stmtUser  = $pdo->query("SELECT id FROM usuarios ORDER BY id ASC LIMIT 1");
    $usuarioId = (int)$stmtUser->fetchColumn();
    if ($usuarioId <= 0) {
        $hash        = password_hash('TestPass2026!', PASSWORD_BCRYPT);
        $stmtInsUser = $pdo->prepare("
            INSERT INTO usuarios (nombre, email, password_hash, rol, activo)
            VALUES ('Contador QA', :email, :hash, 'ADMIN', 1)
        ");
        $stmtInsUser->execute([':email' => 'qa_' . time() . '@estudio.cl', ':hash' => $hash]);
        $usuarioId = (int)$pdo->lastInsertId();
    }
    afirmar($usuarioId > 0, "Usuario contador disponible (ID #{$usuarioId})");

    // ---------------------------------------------------------------------
    // BLOQUE 2: Creación Multi-Tenant de Empresas y Plan de Cuentas Base SII
    // ---------------------------------------------------------------------
    echo "\n2. Probando alta multi-tenant de empresas y Plan de Cuentas SII...\n";
    $semilla     = random_int(76100000, 76899999);
    $rutEmpresaA = generarRutValido($semilla);
    $rutEmpresaB = generarRutValido($semilla + 1);

    $empresaAId = $empresaService->crearEmpresa([
        'rut'                => $rutEmpresaA,
        'razon_social'       => 'Servicios Tecnológicos QA SpA',
        'giro'               => 'Desarrollo de Software y Consultoría TI',
        'direccion'          => 'Av. Apoquindo 4500',
        'comuna'             => 'Las Condes',
        'regimen_tributario' => 'PRO_PYME_GENERAL',
        'tasa_ppm'           => '0.0025',
    ], $usuarioId);
    $empresasCreadasIds[] = $empresaAId;

    $empresaBId = $empresaService->crearEmpresa([
        'rut'                => $rutEmpresaB,
        'razon_social'       => 'Comercializadora Aislada QA Ltda.',
        'giro'               => 'Comercio al por mayor',
        'direccion'          => 'Av. Providencia 1200',
        'comuna'             => 'Providencia',
        'regimen_tributario' => 'GENERAL_14A',
        'tasa_ppm'           => '0.0100',
    ], $usuarioId);
    $empresasCreadasIds[] = $empresaBId;

    afirmar($empresaAId > 0 && $empresaBId > 0 && $empresaAId !== $empresaBId, "Empresas A ({$rutEmpresaA}) y B ({$rutEmpresaB}) creadas con IDs aislados");

    // Abrir período fiscal de prueba para Empresa A
    $anioPrueba = (int)date('Y');
    $mesPrueba  = (int)date('n');
    $fechaHoy   = sprintf('%04d-%02d-15', $anioPrueba, $mesPrueba);

    $stmtPer = $pdo->prepare("SELECT id FROM periodos_fiscales WHERE empresa_id = :eid AND anio = :anio AND mes = :mes LIMIT 1");
    $stmtPer->execute([':eid' => $empresaAId, ':anio' => $anioPrueba, ':mes' => $mesPrueba]);
    $periodoAId = (int)$stmtPer->fetchColumn();

    if ($periodoAId <= 0) {
        $stmtInsPer = $pdo->prepare("INSERT INTO periodos_fiscales (empresa_id, anio, mes, estado) VALUES (:eid, :anio, :mes, 'ABIERTO')");
        $stmtInsPer->execute([':eid' => $empresaAId, ':anio' => $anioPrueba, ':mes' => $mesPrueba]);
        $periodoAId = (int)$pdo->lastInsertId();
    }
    afirmar($periodoAId > 0, "Período fiscal abierto para Empresa A (ID #{$periodoAId})");

    // Crear estructura jerárquica de Plan de Cuentas SII en Empresa A
    $grpActivoId     = $planCuentasService->crearCuenta($empresaAId, ['codigo' => '1', 'nombre' => 'ACTIVO', 'tipo' => 'ACTIVO', 'es_imputable' => 0], $usuarioId);
    $grpPasivoId     = $planCuentasService->crearCuenta($empresaAId, ['codigo' => '2', 'nombre' => 'PASIVO', 'tipo' => 'PASIVO', 'es_imputable' => 0], $usuarioId);
    $grpPatrimonioId = $planCuentasService->crearCuenta($empresaAId, ['codigo' => '3', 'nombre' => 'PATRIMONIO', 'tipo' => 'PATRIMONIO', 'es_imputable' => 0], $usuarioId);
    $grpIngresoId    = $planCuentasService->crearCuenta($empresaAId, ['codigo' => '4', 'nombre' => 'INGRESOS', 'tipo' => 'INGRESO', 'es_imputable' => 0], $usuarioId);
    $grpGastoId      = $planCuentasService->crearCuenta($empresaAId, ['codigo' => '5', 'nombre' => 'GASTOS', 'tipo' => 'GASTO', 'es_imputable' => 0], $usuarioId);

    $ctaBancoId       = $planCuentasService->crearCuenta($empresaAId, ['codigo' => '1.1.01', 'nombre' => 'Banco Estado Cuenta Corriente', 'tipo' => 'ACTIVO', 'es_imputable' => 1, 'cuenta_padre_id' => $grpActivoId, 'codigo_sii' => '110100'], $usuarioId);
    $ctaClientesId    = $planCuentasService->crearCuenta($empresaAId, ['codigo' => '1.1.02', 'nombre' => 'Clientes Nacionales', 'tipo' => 'ACTIVO', 'es_imputable' => 1, 'cuenta_padre_id' => $grpActivoId, 'codigo_sii' => '110300'], $usuarioId);
    $ctaIvaCreditoId  = $planCuentasService->crearCuenta($empresaAId, ['codigo' => '1.1.03', 'nombre' => 'IVA Crédito Fiscal', 'tipo' => 'ACTIVO', 'es_imputable' => 1, 'cuenta_padre_id' => $grpActivoId, 'codigo_sii' => '110700'], $usuarioId);
    $ctaActivoFijoId  = $planCuentasService->crearCuenta($empresaAId, ['codigo' => '1.2.01', 'nombre' => 'Equipos Computacionales', 'tipo' => 'ACTIVO', 'es_imputable' => 1, 'cuenta_padre_id' => $grpActivoId, 'codigo_sii' => '120300'], $usuarioId);

    $ctaProveedoresId = $planCuentasService->crearCuenta($empresaAId, ['codigo' => '2.1.01', 'nombre' => 'Proveedores Nacionales', 'tipo' => 'PASIVO', 'es_imputable' => 1, 'cuenta_padre_id' => $grpPasivoId, 'codigo_sii' => '210200'], $usuarioId);
    $ctaIvaDebitoId   = $planCuentasService->crearCuenta($empresaAId, ['codigo' => '2.1.02', 'nombre' => 'IVA Débito Fiscal', 'tipo' => 'PASIVO', 'es_imputable' => 1, 'cuenta_padre_id' => $grpPasivoId, 'codigo_sii' => '210700'], $usuarioId);
    $ctaRetencionId   = $planCuentasService->crearCuenta($empresaAId, ['codigo' => '2.1.03', 'nombre' => 'Retención Honorarios 14,25%', 'tipo' => 'PASIVO', 'es_imputable' => 1, 'cuenta_padre_id' => $grpPasivoId, 'codigo_sii' => '210800'], $usuarioId);
    $ctaDepAcumId     = $planCuentasService->crearCuenta($empresaAId, ['codigo' => '2.2.01', 'nombre' => 'Depreciación Acumulada Activo Fijo', 'tipo' => 'PASIVO', 'es_imputable' => 1, 'cuenta_padre_id' => $grpPasivoId, 'codigo_sii' => '120900'], $usuarioId);

    $ctaCapitalId     = $planCuentasService->crearCuenta($empresaAId, ['codigo' => '3.1.01', 'nombre' => 'Capital Pagado', 'tipo' => 'PATRIMONIO', 'es_imputable' => 1, 'cuenta_padre_id' => $grpPatrimonioId, 'codigo_sii' => '310100'], $usuarioId);
    $ctaIngresosId    = $planCuentasService->crearCuenta($empresaAId, ['codigo' => '4.1.01', 'nombre' => 'Ingresos por Servicios TI', 'tipo' => 'INGRESO', 'es_imputable' => 1, 'cuenta_padre_id' => $grpIngresoId, 'codigo_sii' => '410100'], $usuarioId);
    $ctaGastosId      = $planCuentasService->crearCuenta($empresaAId, ['codigo' => '5.1.01', 'nombre' => 'Gastos Generales y Honorarios', 'tipo' => 'GASTO', 'es_imputable' => 1, 'cuenta_padre_id' => $grpGastoId, 'codigo_sii' => '510100'], $usuarioId);
    $ctaGastoDepId    = $planCuentasService->crearCuenta($empresaAId, ['codigo' => '5.1.02', 'nombre' => 'Depreciación del Ejercicio', 'tipo' => 'GASTO', 'es_imputable' => 1, 'cuenta_padre_id' => $grpGastoId, 'codigo_sii' => '510400'], $usuarioId);

    $cuentasA    = $planCuentasService->listarPlanCuentas($empresaAId, true);
    $imputablesA = array_values(array_filter($cuentasA, static fn(array $c): bool => (int)$c['es_imputable'] === 1));
    afirmar(count($imputablesA) === 12, 'Plan de Cuentas jerárquico SII creado correctamente (' . count($imputablesA) . ' cuentas imputables)');

    // ---------------------------------------------------------------------
    // BLOQUE 3: Partida Doble Estricta en Libro Diario (AsientoService)
    // ---------------------------------------------------------------------
    echo "\n3. Probando partida doble e integridad ACID en AsientoService...\n";
    $rechazoDescuadre = false;
    try {
        $asientoService->registrarAsiento($empresaAId, $periodoAId, $fechaHoy, 'Asiento descuadrado QA', [
            ['cuenta_id' => $ctaBancoId,   'debe' => '100000.0000', 'haber' => '0.0000',     'glosa_linea' => null],
            ['cuenta_id' => $ctaCapitalId, 'debe' => '0.0000',      'haber' => '99999.0000', 'glosa_linea' => null],
        ]);
    } catch (Throwable $e) {
        $rechazoDescuadre = true;
    }
    afirmar($rechazoDescuadre, 'AsientoService rechaza asiento descuadrado por $1 CLP');

    $asientoAperturaId = $asientoService->registrarAsiento($empresaAId, $periodoAId, $fechaHoy, 'Aporte de capital inicial QA', [
        ['cuenta_id' => $ctaBancoId,   'debe' => '5000000.0000', 'haber' => '0.0000',       'glosa_linea' => 'Depósito capital'],
        ['cuenta_id' => $ctaCapitalId, 'debe' => '0.0000',       'haber' => '5000000.0000', 'glosa_linea' => 'Capital pagado'],
    ]);
    afirmar($asientoAperturaId > 0, "Asiento de apertura cuadrado contabilizado (ID #{$asientoAperturaId})");

    // ---------------------------------------------------------------------
    // BLOQUE 4: Registro de Compras y Ventas SII (RCV + IVA 19%)
    // ---------------------------------------------------------------------
    echo "\n4. Probando Registro de Compras y Ventas (DTE 33 + IVA 19% + F29)...\n";
    $rutProveedor = generarRutValido(76543210);
    $rutCliente   = generarRutValido(96888777);

    $resCompra = $rcvService->registrarDocumento($empresaAId, $periodoAId, [
        'tipo_operacion'           => 'COMPRA',
        'tipo_documento_sii'       => 33,
        'folio'                    => 1001,
        'rut_contraparte'          => $rutProveedor,
        'razon_social_contraparte' => 'Proveedor Insumos Chile SpA',
        'fecha_emision'            => $fechaHoy,
        'modo_ingreso'             => 'DESGLOSADO',
        'monto_total'              => '119000',
        'monto_exento'             => '0',
        'monto_neto'               => '100000',
        'monto_iva'                => '19000',
        'iva_no_recuperable'       => '0',
        'glosa'                    => 'Compra equipamiento oficina QA',
        'cuenta_contraparte_id'    => $ctaProveedoresId,
        'cuenta_resultado_id'      => $ctaGastosId,
        'cuenta_iva_id'            => $ctaIvaCreditoId,
        'usuario_id'               => $usuarioId,
    ]);
    afirmar($resCompra['documento_id'] > 0 && $resCompra['asiento_id'] > 0, 'Factura de Compra DTE 33 contabilizada con asiento automático');

    $resVenta = $rcvService->registrarDocumento($empresaAId, $periodoAId, [
        'tipo_operacion'           => 'VENTA',
        'tipo_documento_sii'       => 33,
        'folio'                    => 501,
        'rut_contraparte'          => $rutCliente,
        'razon_social_contraparte' => 'Cliente Corporativo S.A.',
        'fecha_emision'            => $fechaHoy,
        'modo_ingreso'             => 'DESGLOSADO',
        'monto_total'              => '595000',
        'monto_exento'             => '0',
        'monto_neto'               => '500000',
        'monto_iva'                => '95000',
        'iva_no_recuperable'       => '0',
        'glosa'                    => 'Venta servicios consultoría TI QA',
        'cuenta_contraparte_id'    => $ctaClientesId,
        'cuenta_resultado_id'      => $ctaIngresosId,
        'cuenta_iva_id'            => $ctaIvaDebitoId,
        'usuario_id'               => $usuarioId,
    ]);
    afirmar($resVenta['documento_id'] > 0 && $resVenta['asiento_id'] > 0, 'Factura de Venta DTE 33 contabilizada con asiento automático');

    $resumenF29 = $rcvService->obtenerResumenF29Periodo($empresaAId, $periodoAId);
    afirmar(
        bccomp((string)$resumenF29['iva_determinado_f29'], '76000', 0) === 0,
        'Pre-liquidación F29 exacta: IVA Débito ($95.000) - IVA Crédito ($19.000) = $76.000'
    );

    // ---------------------------------------------------------------------
    // BLOQUE 5: Libro de Honorarios BHE SII 2026 (Retención 14,25%)
    // ---------------------------------------------------------------------
    echo "\n5. Probando Libro de Honorarios Electrónicos (Tasa 14,25% SII 2026)...\n";
    $rutProfesional = generarRutValido(12345678);

    $resBhe = $honorariosService->registrarBoleta($empresaAId, $periodoAId, [
        'numero_boleta'       => 42,
        'rut_profesional'     => $rutProfesional,
        'razon_social'        => 'Consultor Tributario Independiente',
        'fecha_emision'       => $fechaHoy,
        'modo_calculo'        => 'BRUTO',
        'monto_ingresado'     => '1000000',
        'glosa'               => 'Asesoría auditoría tributaria',
        'cuenta_gasto_id'     => $ctaGastosId,
        'cuenta_retencion_id' => $ctaRetencionId,
        'cuenta_por_pagar_id' => $ctaProveedoresId,
        'usuario_id'          => $usuarioId,
    ]);

    $retBhe = bcadd((string)$resBhe['desglose']['retencion'], '0', 0);
    $liqBhe = bcadd((string)$resBhe['desglose']['liquido'], '0', 0);
    afirmar(
        $retBhe === '142500' && $liqBhe === '857500',
        "BHE Bruto $1.000.000 -> Retención 14,25% ($142.500) y Líquido ($857.500) exactos"
    );

    // ---------------------------------------------------------------------
    // BLOQUE 6: Activo Fijo y Depreciación Tributaria SII (si está instalado)
    // ---------------------------------------------------------------------
    if ($tieneActivoFijo && $pdo->query("SHOW TABLES LIKE 'activos_fijos'")->fetchColumn()) {
        echo "\n6. Probando Activo Fijo y centralización de depreciación mensual...\n";
        $afService = new ActivoFijoService($pdo, $asientoService, $auditoriaService);
        $activoId  = $afService->registrarActivo($empresaAId, [
            'codigo_interno'          => 'AF-QA-01',
            'nombre'                  => 'Servidor Rack QA',
            'categoria'               => 'Equipos Computacionales',
            'fecha_adquisicion'       => $fechaHoy,
            'fecha_puesta_marcha'     => $fechaHoy,
            'regimen_depreciacion'    => 'NORMAL',
            'vida_util_normal_meses'  => 72,
            'valor_adquisicion'       => '720001',
            'cuenta_activo_id'        => $ctaActivoFijoId,
            'cuenta_dep_acumulada_id' => $ctaDepAcumId,
            'cuenta_gasto_dep_id'     => $ctaGastoDepId,
        ], $usuarioId);

        $resDep = $afService->contabilizarDepreciacionPeriodo($empresaAId, $periodoAId, $usuarioId, '0.00');
        afirmar(
            $activoId > 0 && $resDep['procesados'] === 1 && $resDep['total_depreciacion'] === '10000',
            'Depreciación mensual calculada sobre base ($720.001 - $1 residual) / 72m = $10.000'
        );
    } else {
        echo "\n6. [OMITIDO] Migración 004_activo_fijo.sql aún no aplicada en MySQL.\n";
    }

    // ---------------------------------------------------------------------
    // BLOQUE 7: Estados Financieros Oficiales (Mayor, Balance 8 Col, EERR)
    // ---------------------------------------------------------------------
    echo "\n7. Probando Estados Financieros (Libro Mayor, Balance 8 Columnas y EERR)...\n";
    $mayorBanco = $reportesService->obtenerLibroMayor($empresaAId, $ctaBancoId, $periodoAId);
    afirmar(is_array($mayorBanco) && !empty($mayorBanco), 'Libro Mayor analítico generado correctamente');

    $balance8 = $reportesService->obtenerBalanceTributario($empresaAId, $periodoAId);
    afirmar(is_array($balance8) && !empty($balance8), 'Balance Tributario de 8 Columnas generado correctamente');

    $eerr = $reportesService->obtenerEstadoResultados($empresaAId, $periodoAId);
    afirmar(is_array($eerr) && !empty($eerr), 'Estado de Resultados generado correctamente');

    // ---------------------------------------------------------------------
    // BLOQUE 8: Aislamiento Multi-Tenant y Trazabilidad Auditoría (ADR-002)
    // ---------------------------------------------------------------------
    echo "\n8. Verificando aislamiento multi-tenant y bitácora de auditoría...\n";
    $f29EmpresaB = $rcvService->obtenerResumenF29Periodo($empresaBId, $periodoAId);
    afirmar(
        bccomp((string)$f29EmpresaB['ventas_netas'], '0', 0) === 0 &&
        bccomp((string)$f29EmpresaB['compras_netas'], '0', 0) === 0,
        'Aislamiento multi-tenant estricto: Empresa B no visualiza movimientos de Empresa A'
    );

    $eventosAuditoria = $auditoriaService->listarEventos($empresaAId, [], 50);
    afirmar(count($eventosAuditoria) >= 3, 'Bitácora inmutable de auditoría registró eventos críticos (' . count($eventosAuditoria) . ' registros)');

    echo "\n=================================================================\n";
    echo " RESULTADO FINAL: {$pruebasPasadas} PRUEBAS PASADAS | {$pruebasFallidas} FALLIDAS\n";
    echo "=================================================================\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\n[ERROR EN SUITE DE PRUEBAS] " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
} finally {
    // Limpieza segura: desactivar empresas QA para no consumir cupo de licencia
    if (!empty($empresasCreadasIds)) {
        $stmtLimpiar = $pdo->prepare("UPDATE empresas SET activa = 0 WHERE id = :id");
        foreach ($empresasCreadasIds as $eidLimpiar) {
            $stmtLimpiar->execute([':id' => $eidLimpiar]);
        }
        echo "\n[LIMPIEZA] Empresas temporales QA desactivadas (activa = 0). Cupo de licencia restaurado.\n";
    }
}

exit($pruebasFallidas === 0 ? 0 : 1);