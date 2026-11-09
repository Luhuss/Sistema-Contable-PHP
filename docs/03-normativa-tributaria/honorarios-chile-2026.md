### 3. `/docs/03-normativa-tributaria/honorarios-chile-2026.md`

```markdown
# Normativa Tributaria: Boletas de Honorarios Electrónicas (Chile 2026)

## Reglas de Cálculo y Tasas Retención

De acuerdo con la retención gradual establecida por la Ley N° 21.133, la tasa aplicable a las Boletas de Honorarios Electrónicas (BHE) emitidas durante el año comercial 2026 es del **14.25%**.

### 1. Cálculo desde Monto Bruto
El usuario ingresa el valor total pactado (Monto Bruto):

$$\text{Retención} = \text{round}(\text{Bruto} \times 0.1425)$$

$$\text{Líquido} = \text{Bruto} - \text{Retención}$$

### 2. Cálculo desde Monto Líquido (Despeje Monetario)
El usuario ingresa el valor neto a recibir (Monto Líquido):

$$\text{Factor Divisor} = 1 - 0.1425 = 0.8575$$

$$\text{Bruto} = \text{round}\left(\frac{\text{Líquido}}{0.8575}\right)$$

$$\text{Retención} = \text{Bruto} - \text{Líquido}$$

> **Regla del Negocio:** En ambos casos, el tercer componente se calcula mediante **resta directa** para eliminar cualquier diferencia de $\pm 1$ CLP generada por redondeos truncados.

### 3. Validación de RUT (Módulo 11)
- Eliminación de puntos, guiones y caracteres de formateo.
- Verificación estricta de cuerpo numérico continuo mediante `ctype_digit()`. Soporta 7 y 8 dígitos de cuerpo más dígito verificador ($0-9, K$).
- Aplicación de serie de ponderadores $[2, 3, 4, 5, 6, 7]$ de derecha a izquierda sobre el cuerpo numérico.