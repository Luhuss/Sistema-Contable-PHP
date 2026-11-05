class AsientoService {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
    * Registrar un asiento contable inmutable respetando la partida doble.
    *
    * @param int $empresaId
    * @param int $periodoId
    * @param string $fecha (Formato YYYY-MM-DD)
    * @param string $glosa
    * @param array $lineas Array de ['cuenta_id' => int, 'debe' => string, 'haber' => string]
    * @return int ID del asiento creado
    * @throws Exception Si la partida doble no cuadra o falla la de datos.
    */

    public function registrarAsiento(int $empresaId, int $periodoId, string $fecha, string $glosa, array $lineas): int {
        if (empty($lineas)) {
            throw new Exception("El asiento contable debe contener al menos dos apuntes.");
        }

        // 1. Validar Partida Doble con BCMath (Escala 4 decimales)
        $totalDebe = "0.0000";
        $totalHaber = "0.0000";

        foreach ($lineas as $linea) {
            $debe = number_format((float)$linea['debe'], 4, '.', '');
            $haber = number_format((float)$linea['haber'], 4, '.', '');

            $totalDebe = bcadd($totalDebe, $debe, 4);
            $totalHaber = bcadd($totalHaber, $haber, 4);
        }

        // Comparar $totalDebe y $totalHaber (Retorna 0 si son exactamente iguales)
        if (bccomp($totalDebe, $totalHaber, 4) !== 0) {
            throw new Exception("Error de Partida Doble: La suma del DEBE ({$totalDebe}) no cuadra con el HABER ({$totalHaber}).");
        }

        // 2 Transacción ACID PDO
        
    }
}