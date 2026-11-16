### 3. `docs/03-normativa-tributaria/honorarios-chile-2026.md`

```markdown
# Normativa Tributaria Chilena Aplicada (SII 2026)

## 1. Boletas de Honorarios Electrónicas (BHE) — Ley N° 21.133

De acuerdo con la retención gradual establecida por la Ley N° 21.133, la tasa aplicable a las Boletas de Honorarios Electrónicas (BHE) emitidas durante el año comercial **2026** es del **14.25%**.

### Cálculo desde Monto Bruto
El usuario ingresa el valor total pactado (Monto Bruto):

$$\text{Retención} = \text{round}(\text{Bruto} \times 0.1425)$$

$$\text{Líquido} = \text{Bruto} - \text{Retención}$$

### Cálculo desde Monto Líquido (Despeje Monetario)
El usuario ingresa el valor neto a recibir (Monto Líquido):

$$\text{Factor Divisor} = 1 - 0.1425 = 0.8575$$

$$\text{Bruto} = \text{round}\left(\frac{\text{Líquido}}{0.8575}\right)$$

$$\text{Retención} = \text{Bruto} - \text{Líquido}$$

> **Regla del Negocio:** En ambos casos, el tercer componente se calcula mediante **resta directa** para eliminar cualquier diferencia de $\pm 1$ CLP generada por redondeos truncados.

### Centralización Contable BHE
- **Debe:** Cuenta de Gasto por Honorarios (`Monto Bruto`).
- **Haber:** Cuenta de Pasivo Retención Honorarios F29 (`14.25%`).
- **Haber:** Cuenta de Pasivo Honorarios por Pagar (`85.75%`).

---

## 2. Validación de RUT Chileno (Módulo 11)
- Eliminación de puntos, guiones y caracteres de formateo.
- Verificación estricta de cuerpo numérico continuo mediante `ctype_digit()`. Soporta 7 y 8 dígitos de cuerpo más dígito verificador ($0-9, K$).
- Aplicación de serie de ponderadores $[2, 3, 4, 5, 6, 7]$ de derecha a izquierda sobre el cuerpo numérico.

---

## 3. Registro de Compras y Ventas (RCV) e IVA 19% (Formulario 29)
- **Tasa General IVA:** 19% aplicado sobre montos netos afectos en DTE electrónicos.
- **Determinación Mensual F29:**
  $$\text{IVA Determinado F29} = \text{IVA Débito Fiscal (Ventas)} - \text{IVA Crédito Fiscal Recuperable (Compras)}$$
- **IVA No Recuperable:** Se contabiliza directamente como mayor costo o gasto sin incrementar el crédito fiscal del período.

---

## 4. Regímenes Tributarios y Depreciación de Activo Fijo (LIR)
- **Regímenes Soportados:** `PRO_PYME_GENERAL` (Art. 14 D N°3), `PRO_PYME_TRANSPARENTE` (Art. 14 D N°8), `GENERAL_14A` (Art. 14 A) y `RENTA_PRESUNTA`.
- **Regímenes de Depreciación:**
  - **Normal:** Cuota mensual lineal según tabla de vida útil normal del SII sobre la base depreciable ($\text{Valor Activo} - \$1\text{ CLP}$).
  - **Acelerada (Art. 31 N°5 LIR):** Reducción a un tercio ($1/3$) de la vida útil normal para bienes nuevos o importados.
  - **Instantánea 100% Pro Pyme (Art. 14 D LIR):** Depreciación íntegra en el ejercicio de adquisición/puesta en marcha para contribuyentes acogidos a regímenes `PRO_PYME`, conservando el registro patrimonial de `$1 CLP`.