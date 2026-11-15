# Sistema Contable Multi-Tenant Chile (SII 2026)

Plataforma SaaS de contabilidad y gestión tributaria multi-empresa diseñada para estudios contables y pymes en Chile. Implementa aislamiento estricto por empresa (`empresa_id`), aritmética financiera exacta mediante `BCMath`, partida doble con transacciones ACID y bitácora inmutable de auditoría.

---

## Stack Tecnológico

* **Backend:** PHP 8.4+ (`declare(strict_types=1)`, PDO preparado, extensión `bcmath` obligatoria)
* **Base de Datos:** MySQL 8.0+ (InnoDB, `utf8mb4_unicode_ci`, restricciones de integridad referencial y unicidad por tenant)
* **Frontend:** HTML5, CSS3 modular y JavaScript nativo sin dependencias pesadas

---

## Módulos Implementados

1. **Gestión Multi-Tenant y Licenciamiento (`EmpresaService`)**
   * Administración aislada de múltiples empresas con validación de RUT chileno (Módulo 11).
   * Soporte para regímenes tributarios chilenos: `PRO_PYME_GENERAL` (Art. 14 D N°3), `PRO_PYME_TRANSPARENTE` (Art. 14 D N°8), `GENERAL_14A` (Art. 14 A) y `RENTA_PRESUNTA`.
   * Control de cupos de empresas activas por licencia y configuración de tasa PPM.

2. **Plan de Cuentas Jerárquico SII (`PlanCuentasService`)**
   * Estructura multinivel con validación de naturaleza contable (`ACTIVO`, `PASIVO`, `PATRIMONIO`, `INGRESO`, `GASTO`, `COSTO`).
   * Homologación con códigos del Servicio de Impuestos Internos (`codigo_sii`) y bloqueo de imputación directa sobre cuentas agrupadoras.

3. **Libro Diario y Partida Doble ACID (`AsientoService`)**
   * Registro de comprobantes contables de ingreso, egreso y traspaso.
   * Validación estricta de cuadratura (`Debe == Haber`) con precisión decimal de 4 posiciones antes de confirmar transacciones.

4. **Registro de Compras y Ventas - RCV (`LibroComprasVentasService`)**
   * Centralización automática de DTE (Facturas Electrónicas 33, Exentas 34, Notas de Crédito 61, Notas de Débito 56, Boletas).
   * Cálculo exacto de IVA 19%, IVA no recuperable y pre-liquidación mensual del **Formulario 29 (F29)**.

5. **Libro de Honorarios Electrónicos - BHE (`LibroHonorariosService`)**
   * Cálculo automático en modo Bruto y Líquido con retención legal vigente **14,25% (SII 2026)**.
   * Centralización contable y flujo de anulación auditada (`ServicioAnulacionHonorariosService`).

6. **Activo Fijo y Depreciación Tributaria SII (`ActivoFijoService`)**
   * Control de bienes físicos del activo inmovilizado con tabla referencial de vida útil SII.
   * Cálculo de Corrección Monetaria (Art. 41 LIR) y depreciación **Normal**, **Acelerada (1/3 vida útil)** e **Instantánea 100% Pro Pyme (Art. 14 D)** conservando valor residual simbólico de `$1 CLP`.
   * Centralización mensual automatizada en Libro Diario.

7. **Estados Financieros Oficiales (`ReportesServices`)**
   * **Libro Mayor** analítico por cuenta y período.
   * **Balance Tributario de 8 Columnas** (Débitos, Créditos, Deudor, Acreedor, Activo, Pasivo, Pérdida, Ganancia).
   * **Estado de Resultados (EERR)** comparativo y por período fiscal.

8. **Trazabilidad y Auditoría Inmutable (`AuditoriaService`)**
   * Registro automático de eventos críticos (altas, modificaciones, centralizaciones, anulaciones y bajas) con identificación de usuario, empresa y marca de tiempo.

---

## Estructura del Proyecto

```text
├── config/                 # Configuración de aplicación, sesión y conexión PDO
├── docs/                   # Documentación técnica, arquitectura y ADRs
├── includes/               # Layout compartido, navegación y helpers de vista
├── public/                 # Controladores y vistas web (endpoints HTTP)
├── sql/                    # Esquema base y migraciones incrementales (001 - 004)
├── src/                    # Servicios de dominio organizados por contexto
│   ├── ActivoFijo/
│   ├── Auditoria/
│   ├── Contabilidad/
│   ├── Cuentas/
│   ├── Empresas/
│   ├── Reportes/
│   └── Tributario/
└── tests/                  # Suites de validación de integridad y flujo E2E