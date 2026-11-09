# Sistema Contable y Tributario Web Modular (Chile 2026)

Un sistema monolítico modular desarrollado en PHP puro y MySQL, diseñado para despliegue en entornos web. Enfocado en precisión matemática financiera (`BCMath`), arquitectura transaccional ACID estricta y cumplimiento con las normativas del Servicio de Impuestos Internos (SII) de Chile para el año comercial 2026.

---

## 📌 Visión del Proyecto

El objetivo de este proyecto es construir un motor contable web inmutable, robusto y auditable para la gestión de libros tributarios y contabilidad general de pequeñas y medianas empresas en Chile, garantizando:
- **Cero variaciones de descalce:** Cuadratura exacta a peso entero ($1 CLP) mediante resta directa.
- **Integridad y trazabilidad absoluta:** Registros contables y tributarios inmutables sin sentencias `DELETE` ni `UPDATE` destructivas.
- **Soporte Multi-tenancy:** Aislamiento estricto de datos por `empresa_id` y `periodo_id` (diseñado para soportar hasta 5 empresas).

---

## 🛠️ Entorno y Requisitos Técnicos

- **Entorno de Producción (Servidor Web):** Servidor Linux / Apache o Nginx
- **Entorno de Desarrollo Local:** macOS
- **Lenguaje:** PHP 8.1+ (Requerida extensión nativa `ext-bcmath` y `ext-pdo`)
- **Base de Datos Target:** MySQL 8.0+ / MariaDB 10.5+ (Motor InnoDB con soporte para transacciones ACID)
- **Patrón de Persistencia:** Acceso a datos mediante `PDO` con consultas preparadas.
- **Inyección de Dependencias:** Manual mediante constructor.

---

## 🏗️ Estado Actual del Proyecto

El proyecto sigue una metodología de desarrollo por fases (Cascada Modificado), documentando e integrando código probado de forma incremental.

| Componente / Módulo | Descripción | Estado |
| :--- | :--- | :--- |
| **DDL de Base de Datos (`sql/esquema.sql`)** | Estructura de tablas (`asientos`, `libro_honorarios`, `bitacora_auditoria`), llaves foráneas e índices únicos. | `DDL Definido (BBDD Física Pendiente)` |
| **`CalculadoraHonorariosService`** | Core de cálculo bidireccional (Bruto $\leftrightarrow$ Líquido) con retención 14.25% (SII 2026) y redondeo *Round Half Up*. | `Completado y Probado` |
| **`AsientoService`** | Motor de registro de comprobantes contables con validación de partida doble y soporte para transacciones anidadas. | `Completado y Probado` |
| **`LibroHonorariosService`** | Orquestador de persistencia de BHE, validación de RUT Módulo 11 (`ctype_digit`), comprobación de unicidad y despacho contable. | `Completado y Probado` |
| **`ServicioAnulacionHonorarios`** | Gestión de anulación de boletas de honorarios mediante generación de contraasientos contables. | `Pendiente` |
| **`LibroComprasVentasService`** | Registro tributario de facturas, cálculo de IVA (19%) e impuestos adicionales (ILA, combustible). | `En Planificación` |

---

## 📐 Reglas de Negocio y Estándares de Arquitectura

### 1. Precisión Matemática Estricta (`BCMath`)
- Todo cálculo monetario se realiza utilizando la extensión `BCMath` operando únicamente sobre `strings` numéricos.
- Las operaciones intermedias mantienen una precisión interna de **4 decimales** (`DECIMAL(15,4)`), mientras que los montos finales a nivel de Libro e Impuestos F29 se redondean al peso entero más cercano mediante la función auxiliar `bcround()` (*Round Half Up*).
- **Resta directa para cuadratura:** 
  $$\text{Líquido} = \text{Bruto} - \text{Retención}$$
  Evita algebraicamente descuadres por aproximaciones de $\$1$ CLP.

### 2. Normativa Tributaria de Honorarios (Chile 2026)
- **Tasa de Retención BHE:** `14.25%` (`0.1425`).
- **Factor Divisor para Cálculo Líquido $\rightarrow$ Bruto:** `0.8575` ($1 - 0.1425$).
- **Validación de RUT:** Algoritmo Módulo 11 con sanitización y verificación `ctype_digit` en el cuerpo numérico (soporta RUTs de 7 y 8 dígitos más DV) para prevenir fallos por casting o inyección de caracteres.

### 3. Inmutabilidad y Transaccionalidad ACID
- **Inmutabilidad:** Los asientos contables y los registros del libro no se pueden modificar ni eliminar. Las correcciones se realizan únicamente mediante procesos de anulación por contraasiento.
- **Transacciones Anidadas:** `AsientoService` evalúa la bandera `$transaccionPropia` mediante `$pdo->inTransaction()`. Si es invocado por un orquestador superior (como `LibroHonorariosService`), delega el `commit` / `rollBack` a la transacción global del servicio padre.

---

## 🗄️ Esquema de Base de Datos (`sql/esquema.sql`)

### Tablas Principales
- **`periodos_fiscales`**: Control de aperturas y cierres de mes/año contable.
- **`asientos`**: Cabecera de comprobantes contables (consecutivo único por empresa y período).
- **`detalle_asientos`**: Apuntes contables unidireccionales (DEBE / HABER a 4 decimales).
- **`libro_honorarios`**: Auditoría tributaria de Boletas de Honorarios Electrónicas recibidas/emitidas.
- **`bitacora_auditoria`**: Registro inalterable de operaciones del sistema (`INSERT`, origen de datos, IP, usuario).

### Restricciones Clave de Unicidad Documental
```sql
UNIQUE KEY uk_bhe_empresa_rut_numero (empresa_id, rut_profesional, numero_boleta)