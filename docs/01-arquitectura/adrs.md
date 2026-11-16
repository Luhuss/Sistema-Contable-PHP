# Architecture Decision Records (ADRs)

## ADR-001: Uso Estricto de BCMath a 4 Decimales Internos
- **Estado:** Aprobado
- **Contexto:** En aplicaciones contables, el uso de tipos de datos de punto flotante (`float`/`double`) en PHP genera errores acumulativos de imprecisión binaria IEEE 754.
- **Decisión:** Operar todos los montos monetarios mediante la extensión `BCMath` sobre cadenas de texto (`string`), manteniendo una precisión interna de 4 decimales (`DECIMAL(15,4)` en MySQL). El redondeo a pesos enteros ($1 CLP) se aplica únicamente al generar comprobantes finales o reportes del Formulario 29 mediante el algoritmo *Round Half Up*.
- **Consecuencias:** Garantía de cuadratura exacta sin pérdidas ni ganancias fantasma por aproximación decimal.

## ADR-002: Inmutabilidad Contable y Anulación por Contraasiento
- **Estado:** Aprobado
- **Contexto:** Las normas de auditoría y los principios contables generalmente aceptados prohíben la alteración o eliminación de registros históricos.
- **Decisión:** Bloquear operaciones `UPDATE` (sobre valores monetarios) y `DELETE` en la tabla `asientos` y `detalle_asientos`. Cualquier corrección o anulación se realiza generando un nuevo comprobante contable (contraasiento) que invierte de forma exacta los débitos y créditos del asiento original.
- **Consecuencias:** Trazabilidad inalterable y auditoría transparente en el tiempo.

## ADR-003: Manejo de Transacciones Anidadas y Aislamiento ACID
- **Estado:** Aprobado
- **Contexto:** `AsientoService` puede ser invocado directamente o como subproceso dentro de un orquestador tributario (`LibroHonorariosService`, `LibroComprasVentasService`, `ActivoFijoService`).
- **Decisión:** Evaluar si la conexión PDO ya se encuentra dentro de un bloque activo mediante `$pdo->inTransaction()`. Si existe una transacción iniciada por el orquestador principal, el servicio secundario no ejecuta `beginTransaction()`, `commit()` ni `rollBack()`, delegando la atomicidad al servicio de nivel superior.
- **Consecuencias:** Prevención de excepciones PDO por transacciones anidadas no soportadas y garantía de consistencia atómica global.

## ADR-004: Aislamiento Multi-Tenant Estricto y Control de Licencias
- **Estado:** Aprobado
- **Contexto:** Un estudio contable gestiona múltiples empresas independientes dentro de una misma base de datos compartida, bajo un límite comercial de empresas activas (`LICENCIA_MAX_EMPRESAS`).
- **Decisión:** Exigir `empresa_id` en todas las consultas y mutaciones de los servicios de dominio, validar unicidad de RUT (Módulo 11) en `empresas` y controlar el cupo de licencias exclusivamente sobre registros con `activa = 1`.
- **Consecuencias:** Cero visibilidad cruzada de información financiera entre empresas y control preciso del licenciamiento SaaS.

## ADR-005: Detección Dinámica de Regímenes Tributarios en Activo Fijo
- **Estado:** Aprobado
- **Contexto:** Las empresas acogidas a regímenes Pro Pyme (`PRO_PYME_GENERAL` Art. 14 D N°3 y `PRO_PYME_TRANSPARENTE` Art. 14 D N°8) tienen derecho a depreciación instantánea del 100%, mientras que el régimen General (`GENERAL_14A`) opera bajo depreciación normal o acelerada.
- **Decisión:** Evaluar el prefijo `PRO_PYME` sobre `regimen_tributario` de la empresa activa (`str_starts_with($regimenEmpresa, 'PRO_PYME')`) para preseleccionar automáticamente el régimen `INSTANTANEA_14D`, resguardando siempre un valor residual simbólico de `$1 CLP` al término de la vida útil.
- **Consecuencias:** Cumplimiento tributario alineado con la Ley sobre Impuesto a la Renta (LIR) y reducción de errores operativos en el alta de activos.

## ADR-006: Validación Integral End-to-End en CLI
- **Estado:** Aprobado
- **Contexto:** Se requiere verificar de forma determinista y repetible que cambios en servicios o migraciones SQL no rompan la cuadratura contable, los cálculos tributarios ni el aislamiento multi-tenant.
- **Decisión:** Mantener la suite `tests/test_flujo_completo.php` ejecutable por CLI con 17 aserciones sobre infraestructura, multi-tenancy, Plan de Cuentas jerárquico, partida doble, RCV (IVA 19% / F29), BHE (14,25%), Activo Fijo, Estados Financieros y bitácora de auditoría, desactivando automáticamente (`activa = 0`) las empresas QA al finalizar.
- **Consecuencias:** Regresión controlada en segundos sin consumir cupos permanentes de licencia.