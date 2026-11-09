# Especificación del Modelo de Datos e Inmutabilidad

## Principios de Diseño
1. **Multi-tenancy:** Toda tabla de datos contables o tributarios contiene la columna `empresa_id` como primer elemento de filtrado e indexación.
2. **Unicidad Documental:** Evita duplicidad de Boletas de Honorarios Electrónicas recibidas mediante la restricción de clave única:
   `UNIQUE KEY uk_bhe_empresa_rut_numero (empresa_id, rut_profesional, numero_boleta)`

## Diagrama Relacional de Componentes Construidos

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