class CalculadoraHonorariosService {
    
    // Tasa de Retención legal de Honorarios vigente para el año comercial 2026 (14,2500%)
    private const TASA_RETENCION = '0.1425';
    // Factor divisor para despeje desde el Líquido: (1 - 0.1425) = 0.8575
    private const FACTOR_DIVISOR = '0.8575';

    /**
    *Calcula el desglose de una Boleta de Honorarios a partir del monto Bruto ingresado.
    *
    *@param string $montoBruto Monto bruto ingresado por el usuario (ej.: "100000")
    *@return array ['bruto' => string, 'retencion' => string, 'liquido' => string]
    *@throws Exception Si el monto no es un número válido o es menor o igual a cero .
    */
    public static function calcularDesdeBruto(string $montoBruto): array {
        $brutoClean = self::sanitizarYValidarMonto($montoBruto);

        // 1. Retención temporal con precision de 4 decimales: Bruto * 0.1425
        $retencionTemporal = bcmul($brutoClean, self::TASA_RETENCION, 4);

        // 2. Redondeo matemático estricto (Round Half Up) al peso entero más cercano
        $retencionRedondeada = self::bcround($retencionTemporal, 0);

        // 3. Liquido por resta directa: Líquido = Bruto - Retención
        // Garantiza algebraicamente que Bruto - Retención === Líquido sin descalces de $1 peso
        $liquidoCalculado = bcsub($brutoClean, $retencionRedondeada, 0);

        // 4. Verificación de cuadratura 
        if (bccomp(bcadd($liquidoCalculado, $retencionRedondeada, 0), $brutoClean, 0) !== 0) {
            throw new Exception("Error de cuadratura algebraica en el cálculo de honorarios desde Bruto.");
        }

        return [
            'bruto'         => $brutoClean,             // Entero exacto en string (ej: "100000")
            'retencion'     => $retencionRedondeada,     // Entero exacto en string (ej: "14250")
            'liquido'       => $liquidoCalculado        // Entero exacto en string (ej: "85750")
        ];
    }

    /**
    *Calcula el desglose de una Boleta de Honorarios a partir del monto Líquido deseado.
    *
    *@param string $montoLiquido Monto líquido deseado por el prestador (eje.: "85750")
    *@return array ['bruto' => string, 'retencion' => string, 'liquido' => string]
    *@throws Exception Si el monto no es número válido o es menor o igual a cero.
    */
    public static function calcularDesdeLiquido(string $montoLiquido): array {
        $liquidoClean = self::sanitizarYValidarMonto($montoLiquido);

        // 1. Bruto temporal con precisión de 4 decimales: Bruto = Líquido / 0.8575
        $brutoTemporal = bcdiv($liquidoClean, self::FACTOR_DIVISOR, 4);

        // 2. Redondeo matemático estricto (Round Half Up) al peso entero más cercano
        $brutoRedondeado = self::bcround($brutoTemporal, 0);

        // 3. Retención por resta directa: Retención = Bruto - Líquido
        // Garantiza algebraicamaente que Líquido + Retención === Bruto exacto
        $retencionCalculada = bcsub($brutoRedondeado, $liquidoClean, 0);

        // 4. Verificación de cuadratura
        if (bccomp(bcadd($liquidoClean, $retencionCalculada, 0), $brutoRedondeado, 0) !== 0) {
            throw new Exception("Error de cuadratura algebraica en el cálculo de honorarios desde Líquido.");
        }

        return [
            'bruto'     => $brutoRedondeado,         // Entero exacto en string (ej: "100000")
            'retencion' => $retencionCalculada, // Entero exacto en string (ej: "14250")
            'liquido'   => $liquidoClean        // Entero exacto en string (ej: "85750")
        ];
    }

    /**
    *Sanitiza y válida que la entrada sea un string numérico válido y mayor a cero.
    *
    *@param string $monto
    *@return string 
    *@throws Exception
    */
    private static function sanitizarYValidarMonto(string $monto): string {
        $montoLimpio = trim($monto);
        if (!is_numeric($montoLimpio)) {
            throw new Exception("El monto ingresado no es un valor numérico válido.");
        }

        // Remueve decimales accidentales y normaliza a entero 
        $montoClean = bcadd($montoLimpio, '0', 0);

        if (bccomp($montoClean, '0', 0) <=0) {
            throw new Exception("El monto de la boleta de honorarios debe ser superior a cero.");
        }

        return $montoClean;
    }

    /**
    *Helper privado para ejecutar redondeo simétrico estándar (Round Half Up) en BCMath.
    *
    *@param string $number Número a redondear
    *@param int $scale Escala decimal objetivo
    *@return string Número redondeando en formato string
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