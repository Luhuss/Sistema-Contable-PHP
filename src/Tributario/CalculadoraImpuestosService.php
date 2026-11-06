class CalculadoraImpuestosService {

    // Tasa IVA Chile vigente (19.0000%)
    private const TASA_IVA = '0.1900';
    private const FACTOR_DIVISOR = '1.1900'; // 1 + TASA_IVA

    /**
     *Desglosa un valor Bruto en Neto e IVA (19%), garantizando consistencia a nivel de enteros según el SII.
     *
     *@param string $montoBruto Valor ingresado por el usuario (ej.: "119000")
     *@return array ['neto' => string, 'iva' => string, 'bruto_recalculado' => string]
     *@throws Exception Si el monto no es válido o es menor o igual a cero.
     */
    public static function desglosarBruto(string $montoBruto): array {
        // Sanitización previa: asegurar string numérico limpio
        $montoLimpio = trim($montoBruto);
        if (!is_numeric($montoLimpio)) {
            throw new Exception("El monto ingresado no es un valor numérico válido.");
        }

        // En Chile los documentos no llevan decimales tributarios, removemos cualquier .0000 accidental
        $brutoClean = bcadd($montoLimpio, '0', 0);

        if (bccomp($brutoClean, '0', 0) <= 0) {
            throw new Exception("El monto bruto debe ser superior a cero.");
        }

        // 1. Neto temporal con precisión interna de 4 decimales
        $netoTemporal = bcdiv($brutoClean, self::FACTOR_DIVISOR, 4);

        // 2. Redondeo matemático estricto (SII) a 0 decimales sin mutar enteros perfectos
        $netoRedondeado = self::bcround($netoTemporal, 0); 

        // 3. IVA por resta directa usando el neto ya redondeado a entero
        $ivaCalculado = bcsub($brutoClean, $netoRedondeado, 0);

        // 4. Verificación final de integridad en enteros
        $brutoVerificado = bcadd($netoRedondeado, $ivaCalculado, 0);

        if (bccomp($brutoVerificado, $brutoClean, 0) !== 0) {
            throw new Exception("Error fatal de cuadratura matemática en el desglose del documento.");
        }

        return [
            'neto'              => $netoRedondeado,       // Entero exacto en formato string (ej: "100000")
            'iva'               => $ivaCalculado,         // Entero exacto en formato string (ej: "19000")
            'bruto_recalculado' => $brutoVerificado       
        ];
    }

    /**
     *Helper privado para ejecutar redondeo simétrico estándar (Round Half Up) en BCMath.
     *
     *@param string $number Número a redondear
     *@param int $scale Escala decimal objetivo
     *@return string Número redondeado en formato string
     */
    private static function bcround(string $number, int $scale = 0): string {
        if (strpos($number, '.') === false) {
            return bcadd($number, '0', $scale);
        }

        // Configurar el desplazamiento para el redondeo matemático (ej: '0.5' para escala 0)
        $offset = '0.' . str_repeat('0', $scale) . '5';

        if (bccomp($number, '0', 4) >= 0) {
            return bcadd($number, $offset, $scale);
        } else {
            return bcsub($number, $offset, $scale);
        }
    }
}
