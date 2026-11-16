<?php

declare(strict_types=1);

require_once __DIR__ . '/PlanCuentasService.php';

/**
 * Servicio de Carga de Plantilla Base del Plan de Cuentas (SII 2026).
 *
 * Depura e instala las 151 cuentas (43 agrupadoras y 108 imputables)
 * en formato jerárquico separado por puntos (ej. 1.1.01.001), delegando
 * validaciones, deducción de naturaleza/nivel y auditoría a PlanCuentasService.
 */
class PlanCuentasPlantillaService
{
    private PDO $pdo;
    private PlanCuentasService $planCuentasService;

    public function __construct(PDO $pdo, PlanCuentasService $planCuentasService)
    {
        $this->pdo = $pdo;
        $this->planCuentasService = $planCuentasService;
    }

    /**
     * Carga el Plan de Cuentas Base SII 2026 para una empresa (operación idempotente).
     *
     * @param int $empresaId ID de la empresa destino
     * @param int $usuarioId ID del usuario que ejecuta la carga
     * @return int Cantidad de cuentas nuevas creadas
     */
    public function cargarPlanBase(int $empresaId, int $usuarioId): int
    {
        if ($empresaId <= 0) {
            throw new InvalidArgumentException('El ID de empresa es obligatorio.');
        }

        $transaccionPropia = !$this->pdo->inTransaction();
        if ($transaccionPropia) {
            $this->pdo->beginTransaction();
        }

        try {
            /** @var array<string, int> $mapaCodigosId */
            $mapaCodigosId = [];
            foreach ($this->planCuentasService->listarPlanCuentas($empresaId, false, false) as $existente) {
                $mapaCodigosId[(string) $existente['codigo']] = (int) $existente['id'];
            }

            $creadas = 0;

            foreach (self::obtenerCatalogoBase() as [$codigo, $nombre, $tipo, $esImputable, $codigoPadre]) {
                if (isset($mapaCodigosId[$codigo])) {
                    continue;
                }

                $cuentaPadreId = null;
                if ($codigoPadre !== null) {
                    if (!isset($mapaCodigosId[$codigoPadre])) {
                        throw new RuntimeException(
                            "Error de jerarquía: la cuenta padre '{$codigoPadre}' no existe para '{$codigo}'."
                        );
                    }
                    $cuentaPadreId = $mapaCodigosId[$codigoPadre];
                }

                $nuevaCuentaId = $this->planCuentasService->crearCuenta(
                    $empresaId,
                    [
                        'codigo'          => $codigo,
                        'nombre'          => $nombre,
                        'tipo'            => $tipo,
                        'es_imputable'    => $esImputable,
                        'cuenta_padre_id' => $cuentaPadreId,
                        'codigo_sii'      => null,
                    ],
                    $usuarioId
                );

                $mapaCodigosId[$codigo] = $nuevaCuentaId;
                $creadas++;
            }

            if ($transaccionPropia) {
                $this->pdo->commit();
            }

            return $creadas;
        } catch (Throwable $e) {
            if ($transaccionPropia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Catálogo ordenado topológicamente (padres antes que hijos) en formato X.X.XX.XXX.
     * Formato: [codigo, nombre, tipo, es_imputable, codigo_padre]
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: int, 4: ?string}>
     */
    public static function obtenerCatalogoBase(): array
    {
        return [
            // Nivel 1: Raíces por naturaleza contable
            ['1',      'ACTIVO',                                     'ACTIVO',     0, null],
            ['1.9',    'DEPRECIACIÓN ACUMULADA (COMPLEMENTARIA)',    'PASIVO',     0, null],
            ['2',      'PASIVO',                                     'PASIVO',     0, null],
            ['2.3',    'PATRIMONIO',                                 'PATRIMONIO', 0, null],
            ['3',      'COSTOS Y GASTOS',                            'GASTO',      0, null],
            ['4',      'INGRESOS',                                   'INGRESO',    0, null],

            // Nivel 2: Subgrupos
            ['1.1',    'Activo Circulante',                          'ACTIVO',     0, '1'],
            ['1.2',    'Activo Fijo',                                'ACTIVO',     0, '1'],
            ['1.3',    'Otros Activos',                              'ACTIVO',     0, '1'],
            ['2.1',    'Pasivo Circulante',                          'PASIVO',     0, '2'],
            ['2.2',    'Pasivo Largo Plazo',                         'PASIVO',     0, '2'],
            ['3.1',    'Costos de Explotación',                      'COSTO',      0, '3'],
            ['3.2',    'Otros Gastos, Impuestos y Depreciaciones',   'GASTO',      0, '3'],
            ['4.1',    'Ingresos de Explotación',                    'INGRESO',    0, '4'],
            ['4.2',    'Otros Ingresos y Reajustes',                 'INGRESO',    0, '4'],

            // Nivel 3: Rubros
            ['1.1.01', 'Disponible (Caja y Bancos)',                 'ACTIVO',     0, '1.1'],
            ['1.1.04', 'Deudores por Venta y Fondos por Rendir',     'ACTIVO',     0, '1.1'],
            ['1.1.06', 'Cuentas Corrientes Socios y Retiros',        'ACTIVO',     0, '1.1'],
            ['1.1.07', 'Impuestos por Recuperar y Créditos SII',     'ACTIVO',     0, '1.1'],
            ['1.1.08', 'Existencias e Inventarios',                  'ACTIVO',     0, '1.1'],
            ['1.1.10', 'Gastos Anticipados e Intereses Diferidos',   'ACTIVO',     0, '1.1'],
            ['1.2.01', 'Vehículos y Material Rodante',               'ACTIVO',     0, '1.2'],
            ['1.2.02', 'Maquinarias y Equipos',                      'ACTIVO',     0, '1.2'],
            ['1.2.03', 'Bienes Raíces y Construcciones',             'ACTIVO',     0, '1.2'],
            ['1.2.04', 'Muebles, Útiles e Instalaciones',            'ACTIVO',     0, '1.2'],
            ['1.2.05', 'Repuestos e Insumos Activo Fijo',            'ACTIVO',     0, '1.2'],
            ['1.3.01', 'Activos Diferidos Largo Plazo',              'ACTIVO',     0, '1.3'],
            ['2.1.02', 'Proveedores Nacionales',                     'PASIVO',     0, '2.1'],
            ['2.1.03', 'Obligaciones por Vehículos',                 'PASIVO',     0, '2.1'],
            ['2.1.04', 'Acreedores y Documentos por Pagar CP',       'PASIVO',     0, '2.1'],
            ['2.1.05', 'Impuestos y Retenciones por Pagar (F29)',    'PASIVO',     0, '2.1'],
            ['2.1.06', 'Remuneraciones y Honorarios por Pagar',      'PASIVO',     0, '2.1'],
            ['2.1.07', 'Leyes Sociales e Instituciones Previsión',   'PASIVO',     0, '2.1'],
            ['2.1.08', 'Cuentas por Pagar Socios y Otros',           'PASIVO',     0, '2.1'],
            ['2.2.01', 'Obligaciones Financieras Largo Plazo',       'PASIVO',     0, '2.2'],
            ['2.3.01', 'Capital Social',                             'PATRIMONIO', 0, '2.3'],
            ['2.3.02', 'Resultados Acumulados y Revalorización',     'PATRIMONIO', 0, '2.3'],
            ['3.1.01', 'Costos Directos de Venta y Operación',       'COSTO',      0, '3.1'],
            ['3.1.02', 'Gastos de Capacitación y Personal',          'GASTO',      0, '3'],
            ['3.1.03', 'Gastos Generales de Administración',         'GASTO',      0, '3'],
            ['3.2.01', 'Gastos Financieros, Tributarios y Deprec.',  'GASTO',      0, '3.2'],
            ['4.1.01', 'Ingresos del Giro',                          'INGRESO',    0, '4.1'],
            ['4.2.01', 'Ingresos Fuera de Explotación y Reajustes',  'INGRESO',    0, '4.2'],

            // Nivel 4: Cuentas Imputables (108 cuentas)
            ['1.1.01.001', 'Caja',                                            'ACTIVO',     1, '1.1.01'],
            ['1.1.01.002', 'Bancos',                                          'ACTIVO',     1, '1.1.01'],
            ['1.1.01.003', 'Recaudación por Liquidar (Transbank / Delivery)', 'ACTIVO',     1, '1.1.01'],
            ['1.1.04.001', 'Clientes Varios',                                 'ACTIVO',     1, '1.1.04'],
            ['1.1.04.002', 'Fondos por Rendir',                               'ACTIVO',     1, '1.1.04'],
            ['1.1.06.001', 'Retiros Acumulados Ejercicios Anteriores',        'ACTIVO',     1, '1.1.06'],
            ['1.1.06.002', 'Retiros',                                         'ACTIVO',     1, '1.1.06'],
            ['1.1.06.003', 'Cuenta Corriente Socio 1',                        'ACTIVO',     1, '1.1.06'],
            ['1.1.06.004', 'Cuenta Corriente Socio 2',                        'ACTIVO',     1, '1.1.06'],
            ['1.1.06.005', 'Aportes por Enterar',                             'ACTIVO',     1, '1.1.06'],
            ['1.1.07.001', 'Impuestos por Recuperar - Renta',                 'ACTIVO',     1, '1.1.07'],
            ['1.1.07.002', 'Impuestos por Recuperar - IVA',                   'ACTIVO',     1, '1.1.07'],
            ['1.1.07.003', 'Imposiciones por Recuperar',                      'ACTIVO',     1, '1.1.07'],
            ['1.1.07.101', 'IVA Crédito Fiscal 19%',                          'ACTIVO',     1, '1.1.07'],
            ['1.1.07.103', 'IVA Crédito Fiscal No Retenido',                  'ACTIVO',     1, '1.1.07'],
            ['1.1.07.201', 'P.P.M. (Pagos Provisionales Mensuales)',          'ACTIVO',     1, '1.1.07'],
            ['1.1.07.301', 'Crédito Impuesto Específico Diésel',              'ACTIVO',     1, '1.1.07'],
            ['1.1.07.302', 'Crédito Art. 33 bis Activo Fijo',                 'ACTIVO',     1, '1.1.07'],
            ['1.1.07.401', 'Contribuciones Bienes Raíces - Crédito',          'ACTIVO',     1, '1.1.07'],
            ['1.1.07.404', 'Crédito Capacitación SENCE',                      'ACTIVO',     1, '1.1.07'],
            ['1.1.08.001', 'Mercaderías',                                     'ACTIVO',     1, '1.1.08'],
            ['1.1.08.002', 'Materia Prima e Insumos',                         'ACTIVO',     1, '1.1.08'],
            ['1.1.10.001', 'Intereses Diferidos Corto Plazo',                 'ACTIVO',     1, '1.1.10'],
            ['1.2.01.001', 'Vehículos de Carga',                              'ACTIVO',     1, '1.2.01'],
            ['1.2.01.002', 'Dep. Acum. Vehículos de Carga',                   'PASIVO',     1, '1.9'],
            ['1.2.01.003', 'Otros Vehículos de Trabajo',                      'ACTIVO',     1, '1.2.01'],
            ['1.2.01.004', 'Dep. Acum. Vehículos',                            'PASIVO',     1, '1.9'],
            ['1.2.01.005', 'Remolques y Carros',                              'ACTIVO',     1, '1.2.01'],
            ['1.2.01.006', 'Dep. Acum. Remolques y Carros',                   'PASIVO',     1, '1.9'],
            ['1.2.01.007', 'Vehículos',                                       'ACTIVO',     1, '1.2.01'],
            ['1.2.01.008', 'Vehículos en Leasing',                            'ACTIVO',     1, '1.2.01'],
            ['1.2.01.010', 'Dif. Valor Comercial Vehículos',                  'ACTIVO',     1, '1.2.01'],
            ['1.2.02.001', 'Grúas',                                           'ACTIVO',     1, '1.2.02'],
            ['1.2.02.003', 'Tractores',                                       'ACTIVO',     1, '1.2.02'],
            ['1.2.02.004', 'Máquinas y Equipos',                              'ACTIVO',     1, '1.2.02'],
            ['1.2.02.005', 'Equipos Computacionales y relacionados',          'ACTIVO',     1, '1.2.02'],
            ['1.2.02.006', 'Dep. Acum. Máquinas y Equipos',                   'PASIVO',     1, '1.9'],
            ['1.2.02.007', 'Maquinarias y Equipos Industriales',              'ACTIVO',     1, '1.2.02'],
            ['1.2.03.001', 'Terrenos',                                        'ACTIVO',     1, '1.2.03'],
            ['1.2.03.002', 'Construcciones',                                  'ACTIVO',     1, '1.2.03'],
            ['1.2.03.003', 'Dep. Acum. Construcciones',                       'PASIVO',     1, '1.9'],
            ['1.2.03.004', 'Obras en Ejecución',                              'ACTIVO',     1, '1.2.03'],
            ['1.2.04.001', 'Muebles, Útiles e Instalaciones',                 'ACTIVO',     1, '1.2.04'],
            ['1.2.04.009', 'Dep. Acum. Muebles, Útiles e Instalaciones',      'PASIVO',     1, '1.9'],
            ['1.2.05.001', 'Repuestos e Insumos',                             'ACTIVO',     1, '1.2.05'],
            ['1.3.01.001', 'Intereses Diferidos Largo Plazo',                 'ACTIVO',     1, '1.3.01'],
            ['2.1.02.001', 'Proveedores',                                     'PASIVO',     1, '2.1.02'],
            ['2.1.03.001', 'Documentos por Pagar - Vehículos',                'PASIVO',     1, '2.1.03'],
            ['2.1.04.001', 'Acreedores Varios Corto Plazo',                   'PASIVO',     1, '2.1.04'],
            ['2.1.04.003', 'Documentos por Pagar',                            'PASIVO',     1, '2.1.04'],
            ['2.1.05.003', 'Impuesto Único a los trabajadores',               'PASIVO',     1, '2.1.05'],
            ['2.1.05.004', 'PPM por Pagar',                                   'PASIVO',     1, '2.1.05'],
            ['2.1.05.005', 'Provisión Impuesto a la Renta',                   'PASIVO',     1, '2.1.05'],
            ['2.1.05.006', 'Contribuciones por Pagar',                        'PASIVO',     1, '2.1.05'],
            ['2.1.05.101', 'IVA Débito Fiscal 19%',                           'PASIVO',     1, '2.1.05'],
            ['2.1.05.102', 'IVA Débito Fiscal No Retenido',                   'PASIVO',     1, '2.1.05'],
            ['2.1.05.201', 'Retención 2ª Categoría BHE (14,25%)',             'PASIVO',     1, '2.1.05'],
            ['2.1.05.401', 'IVA Compras Retenido a Terceros',                 'PASIVO',     1, '2.1.05'],
            ['2.1.06.001', 'Sueldos por Pagar',                               'PASIVO',     1, '2.1.06'],
            ['2.1.06.002', 'Honorarios por Pagar',                            'PASIVO',     1, '2.1.06'],
            ['2.1.07.001', 'Instituciones de Previsión (AFP / Salud)',        'PASIVO',     1, '2.1.07'],
            ['2.1.08.001', 'Préstamos de Socios',                             'PASIVO',     1, '2.1.08'],
            ['2.1.08.003', 'Otros Acreedores Varios',                         'PASIVO',     1, '2.1.08'],
            ['2.2.01.001', 'Préstamos Bancarios Largo Plazo',                 'PASIVO',     1, '2.2.01'],
            ['2.2.01.002', 'Acreedores Leasing Largo Plazo',                  'PASIVO',     1, '2.2.01'],
            ['2.3.01.001', 'Capital',                                         'PATRIMONIO', 1, '2.3.01'],
            ['2.3.02.001', 'Revalorización Capital Propio',                   'PATRIMONIO', 1, '2.3.02'],
            ['2.3.02.101', 'Resultado Ej. Anteriores',                        'PATRIMONIO', 1, '2.3.02'],
            ['2.3.02.201', 'Resultado del Ejercicio',                         'PATRIMONIO', 1, '2.3.02'],
            ['3.1.01.001', 'Costo de Ventas',                                 'COSTO',      1, '3.1.01'],
            ['3.1.01.002', 'Embalajes',                                       'COSTO',      1, '3.1.01'],
            ['3.1.01.003', 'Combustibles Vehículos',                          'COSTO',      1, '3.1.01'],
            ['3.1.01.004', 'Fletes en Compras (Costo)',                       'COSTO',      1, '3.1.01'],
            ['3.1.01.005', 'Mantención Vehículos',                            'COSTO',      1, '3.1.01'],
            ['3.1.01.006', 'Mermas y Desperdicios de Insumos',                'COSTO',      1, '3.1.01'],
            ['3.1.02.002', 'Capacitación y Perfeccionamiento',                'GASTO',      1, '3.1.02'],
            ['3.1.03.001', 'Remuneraciones',                                  'GASTO',      1, '3.1.03'],
            ['3.1.03.002', 'Honorarios Profesionales',                        'GASTO',      1, '3.1.03'],
            ['3.1.03.003', 'Gastos Generales y Varios',                       'GASTO',      1, '3.1.03'],
            ['3.1.03.004', 'Fletes y Despachos (Gasto)',                      'GASTO',      1, '3.1.03'],
            ['3.1.03.005', 'Gastos de Representación',                        'GASTO',      1, '3.1.03'],
            ['3.1.03.006', 'Energía Eléctrica y Servicios Básicos',           'GASTO',      1, '3.1.03'],
            ['3.1.03.007', 'Gastos de Administración',                        'GASTO',      1, '3.1.03'],
            ['3.1.03.008', 'Publicidad',                                      'GASTO',      1, '3.1.03'],
            ['3.1.03.009', 'Arriendos',                                       'GASTO',      1, '3.1.03'],
            ['3.1.03.010', 'Seguros',                                         'GASTO',      1, '3.1.03'],
            ['3.1.03.011', 'Internet y Telefonía',                            'GASTO',      1, '3.1.03'],
            ['3.1.03.012', 'Gastos de Traslado',                              'GASTO',      1, '3.1.03'],
            ['3.1.03.013', 'Asesorías Profesionales',                         'GASTO',      1, '3.1.03'],
            ['3.2.01.001', 'Comisiones Bancarias',                            'GASTO',      1, '3.2.01'],
            ['3.2.01.002', 'Comisiones Medios de Pago y Apps Delivery',       'GASTO',      1, '3.2.01'],
            ['3.2.01.101', 'Impuesto Primera Categoría',                      'GASTO',      1, '3.2.01'],
            ['3.2.01.102', 'Deudores Incobrables',                            'GASTO',      1, '3.2.01'],
            ['3.2.01.103', 'Patentes y Derechos Municipales',                 'GASTO',      1, '3.2.01'],
            ['3.2.01.104', 'Otros Impuestos',                                 'GASTO',      1, '3.2.01'],
            ['3.2.01.105', 'Multas e Intereses',                              'GASTO',      1, '3.2.01'],
            ['3.2.01.401', 'Depreciación Vehículos',                          'GASTO',      1, '3.2.01'],
            ['3.2.01.402', 'Depreciación Maquinarias y Equipos',              'GASTO',      1, '3.2.01'],
            ['3.2.01.403', 'Depreciación Carros y Remolques',                 'GASTO',      1, '3.2.01'],
            ['3.2.01.404', 'Depreciación Muebles, Útiles e Instalaciones',    'GASTO',      1, '3.2.01'],
            ['3.2.01.501', 'Corrección Monetaria (Pérdida)',                  'GASTO',      1, '3.2.01'],
            ['4.1.01.001', 'Ventas',                                          'INGRESO',    1, '4.1.01'],
            ['4.1.01.002', 'Descuentos Ganados',                              'INGRESO',    1, '4.1.01'],
            ['4.2.01.101', 'Resultado Venta Activo Fijo',                     'INGRESO',    1, '4.2.01'],
            ['4.2.01.102', 'Otros Ingresos',                                  'INGRESO',    1, '4.2.01'],
            ['4.2.01.103', 'Reajuste Crédito Fiscal IVA',                     'INGRESO',    1, '4.2.01'],
            ['4.2.01.104', 'Revalorización Capital Propio (Ganancia)',        'INGRESO',    1, '4.2.01'],
            ['4.2.01.105', 'Reajuste PPM',                                    'INGRESO',    1, '4.2.01'],
        ];
    }
}