# Architecture Decision Records (ADRs)

## ADR-001: Uso Estricto de BCMath a 4 Decimales Internos
* **Estado:** Aprobado
* **Contexto:** En aplicaciones contables, el uso de tipos de datos de punto flotante (`float`/`double`) en PHP genera errores acumulativos de imprecisión binaria IEEE 754.
* **Decisión:** Operar todos los montos monetarios mediante la extensión `BCMath` sobre cadenas de texto (`string`), manteniendo una precisión interna de 4 decimales (`DECIMAL(15,4)` en MySQL). El redondeo a pesos enteros ($1 CLP) se aplica únicamente al generar comprobantes finales o reportes del Formulario 29 mediante el algoritmo *Round Half Up*.
* **Consecuencias:** Garantía de cuadratura exacta sin pérdidas ni ganancias fantasma por aproximación decimal.

## ADR-002: Inmutabilidad Contable y Anulación por Contraasiento
* **Estado:** Aprobado
* **Contexto:** Las normas de auditoría y los principios contables generalmente aceptados prohíben la alteración o eliminación de registros históricos.
* **Decisión:** Bloquear operaciones `UPDATE` (sobre valores monetarios) y `DELETE` en la tabla `asientos` y `detalle_asientos`. Cualquier corrección o anulación se realiza generando un nuevo comprobante contable (contraasiento) que invierte de forma exacta los débitos y créditos del asiento original.
* **Consecuencias:** Trazabilidad inalterable y auditoría transparente en el tiempo.

## ADR-003: Manejo de Transacciones Anidadas y Aislamiento ACID
* **Estado:** Aprobado
* **Contexto:** `AsientoService` puede ser invocado directamente o como subproceso dentro de un orquestador tributario (`LibroHonorariosService`).
* **Decisión:** Evaluar si la conexión PDO ya se encuentra dentro de un bloque activo mediante `$pdo->inTransaction()`. Si existe una transacción iniciada por el orquestador principal, el servicio secundario no ejecuta `beginTransaction()`, `commit()` ni `rollBack()`, delegando la atomicidad al servicio de nivel superior.
* **Consecuencias:** Prevención de excepciones PDO por transacciones anidadas no soportadas y garantía de consistencia atómica global.