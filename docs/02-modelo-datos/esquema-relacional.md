# Especificación del Modelo de Datos e Inmutabilidad

## Principios de Diseño
1. **Multi-tenancy:** Toda tabla de datos contables o tributarios contiene la columna `empresa_id` como primer elemento de filtrado e indexación.
2. **Unicidad Documental:** Evita duplicidad de Boletas de Honorarios Electrónicas recibidas mediante la restricción de clave única:
   `UNIQUE KEY uk_bhe_empresa_rut_numero (empresa_id, rut_profesional, numero_boleta)`
3. **Jerarquía Contable Validada:** El catálogo `plan_cuentas` mantiene relación auto-referencial (`cuenta_padre_id`), prohibiendo subcuentas bajo cuentas marcadas como imputables (`es_imputable = 1`) o con distinta naturaleza contable.
4. **Separación de Migraciones:** El esquema base reside en `sql/esquema.sql` y las extensiones patrimoniales en `sql/004_activo_fijo.sql`.

---

## Diagrama Relacional: Núcleo Contable, Honorarios y Auditoría (`sql/esquema.sql`)

```text
+-------------------+        +--------------------+        +---------------------+
| periodos_fiscales |        |  libro_honorarios  |        | bitacora_auditoria  |
+-------------------+        +--------------------+        +---------------------+
| id (PK)           |<-------| id (PK)            |        | id (PK)             |
| empresa_id        |        | empresa_id         |        | empresa_id          |
| anio, mes         |        | periodo_id (FK)    |        | accion              |
| estado            |        | numero_boleta      |        | tabla_afectada      |
+-------------------+        | rut_profesional    |        | registro_id         |
  ^                          | asiento_id (FK)    |---+    | detalles (JSON)     |
  |                          +--------------------+   |    +---------------------+
  |                                                   |
  |      +------------------+        +----------------v---+
  +------|     asientos     |<-------|  detalle_asientos  |
         +------------------+        +--------------------+
         | id (PK)          |        | id (PK)            |
         | empresa_id       |        | asiento_id (FK)    |
         | periodo_id (FK)  |        | cuenta_id (FK)     |
         | numero_asiento   |        | debe DECIMAL(15,4) |
         +------------------+        | haber DECIMAL(15,4)|
                                     +--------------------+


+----------------------+        +-------------------------+
|       empresas       |        |      plan_cuentas       |
+----------------------+        +-------------------------+
| id (PK)              |<-------| id (PK)                 |
| rut (UNIQUE)         |        | empresa_id (FK)         |
| razon_social         |        | cuenta_padre_id (FK)    |
| regimen_tributario   |        | codigo, nombre          |
| tasa_ppm             |        | tipo, naturaleza        |
| activa               |        | es_imputable            |
+----------------------+        | codigo_sii, activa      |
  ^                             +-------------------------+
  |                               ^       ^       ^
  |      +------------------------+-------+-------+
  |      | (cuenta_activo_id / cuenta_dep_acumulada_id / cuenta_gasto_dep_id)
  |      |
+--------+------------------+
|       activos_fijos       |
+---------------------------+
| id (PK)                   |
| empresa_id (FK)           |
| codigo_interno            |
| nombre, categoria         |
| fecha_adquisicion         |
| fecha_puesta_marcha       |
| regimen_depreciacion      |
| vida_util_normal_meses    |
| valor_adquisicion         |
+---------------------------+