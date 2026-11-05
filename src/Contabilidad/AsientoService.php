class AsientoService {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
    * Registrar un asiento contable inmutable respetando la partida doble.
    *
    *@param int $empresaId
    *@param int $periodoId
    *@param string $fecha (Formato YYYY-MM-DD)
    *@param string $glosa
    *@param array $lineas Array de ['cuenta_id' => int, 'debe' => string, 'haber' => string]
    *@return int ID del asiento creado
    *@throws Exception Si la partida doble no cuadra o falla la de datos.
    */

    public function registrarAsiento(int $empresaId, int $periodoId, string $fecha, string $glosa, array $lineas): int {
        if (count($lineas) < 2) {
            throw new Exception("El asiento contable debe contener al menos dos apuntes para cumplir con la partida doble.");
        }

        // 1. Validar Partida Doble con BCMath (Escala 4 decimales) trabajando estrictamente con STRINGS
        $totalDebe = "0.0000";
        $totalHaber = "0.0000";

        // Sanitización inicial y sumatoria usando strings puros para evitar aproximaciones float
        foreach ($lineas as $index => $linea) {

            // Validar asignación de cuenta
            if (empty($linea['cuenta_id'])) {
                throw new Exception("Cada línea del asiento debe estar asignada a una cuenta contable válida.");
            }

            // Aseguramos que existan y reemplazamos valores nulos/vacíos por '0.0000'
            $debeOriginal = trim((string)($linea['debe'] ?? '0'));
            $haberOriginal = trim((string)($linea['haber'] ?? '0'));

            // Forzanmos el formato de string numerico con 4 decimales usando bcadd con cero
            $debeClean = bcadd($debeOriginal, '0', 4);
            $haberClean = bcadd($haberOriginal, '0', 4);

            // Validar que los montos no sean negativos
            if (bccomp($debeClean, '0.0000', 4) < 0 || bccomp($haberClean, '0.0000', 4) < 0) {
                throw new Exception("No se permiten montos negativos en los apuntes contables.");
            }

            // Validar que la línea no sea totalmente nula (Debe y Haber en cero al mismo tiempo)
            if (bccomp($debeClean, '0.0000', 4) === 0 && bccomp($haberClean, '0.0000', 4) === 0) {
                throw new Exception("Cada línea del asiento debe registrar un monto en el DEBE o en el HABER.");
            }

            // Guardamos el valor formateado de vuelta en el array para usarlo en la inserción
            $lineas[$index]['debe_clean'] = $debeClean;
            $lineas[$index]['haber_clean'] = $haberClean;

            $totalDebe = bcadd($totalDebe, $debeClean, 4);
            $totalHaber = bcadd($totalHaber, $haberClean, 4);
        }

        // Validar que el asiento no sea en cero global ($totalDebe > 0)
        if (bccomp($totalDebe, '0.0000', 4) === 0) {
            throw new Exception("El total del asiento contable debe ser mayor a cero.");
        }

        // Comparar $totalDebe y $totalHaber (Retorna 0 si son exactamente iguales)
        if (bccomp($totalDebe, $totalHaber, 4) !== 0) {
            throw new Exception("Error de Partida Doble: La suma del DEBE ({$totalDebe}) no cuadra con el HABER ({$totalHaber}).");
        }

        // 2 Transacción ACID PDO
        try {
            $this->pdo->beginTransaction();

            // Verificar periodo abierto agregando FOR UPDATE para evitar Race Conditions
            $stmtPeriodo = $this->pdo->prepare("
                SELECT cerrado FROM periodos_fiscales 
                WHERE id = :id AND empresa_id = :empresa_id
                FOR UPDATE    
            ");
            $stmtPeriodo->execute([':id' => $periodoId, ':empresa_id' => $empresaId]);
            $periodo = $stmtPeriodo->fetch();

            if (!$periodo || (int)$periodo['cerrado'] === 1) {
                throw new Exception("No se pueden registrar asientos en un periodo fiscal cerrado o inexistente.");
            }

            // Consecutivo del número de asiento dentro del periodo
            $stmtNum = $this->pdo->prepare("
                SELECT COALESCE(MAX(numero_asiento), 0) + 1 
                FROM asientos
                WHERE empresa_id = :empresa_id AND periodo_id = :periodo_id
            ");
            $stmtNum->execute([':empresa_id' => $empresaId, ':periodo_id' => $periodoId]);
            $numeroAsiento = (int)$stmtNum->fetchColumn();

            // Insertar Cabecera de Asiento 
            $stmtAsiento = $this->pdo->prepare("
                INSERT INTO asientos (empresa_id, periodo_id, numero_asiento, fecha, glosa)
                VALUES (:empresa_id, :periodo_id, :numero_asiento, :fecha, :glosa)
            ");
            $stmtAsiento->execute([
                ':empresa_id'     => $empresaId,
                ':periodo_id'     => $periodoId,
                ':numero_asiento' => $numeroAsiento,
                ':fecha'          => $fecha,
                ':glosa'          => $glosa
            ]);

            $asientoId = (int)$this->pdo->lastInsertId();

            // Insertar Líneas del Asiento 
            $stmtDetalle = $this->pdo->prepare("
                INSERT INTO detalle_asientos (asiento_id, cuenta_id, debe, haber)
                VALUES (:asiento_id, :cuenta_id, :debe, :haber)
            ");

            foreach ($lineas as $linea) {
                $stmtDetalle->execute([
                    ':asiento_id' => $asientoId,
                    ':cuenta_id'  => $linea['cuenta_id'],
                    ':debe'       => $linea['debe_clean'], // String puro de BCMath
                    ':haber'      => $linea['haber_clean'], // String puro de BCMath
                ]);
            }

            // Registrar Log Inalterable de Auditoría
            $stmtAuditoria = $this->pdo->prepare("
                INSERT INTO bitacora_auditoria (empresa_id, accion, tabla_afectada, registro_id, detalles, ip_origen)
                VALUES (:empresa_id, 'INSERT', 'asientos', :registro_id, :detalles, :ip)
            ");
            $stmtAuditoria->execute([
                ':empresa_id'   => $empresaId,
                ':registro_id'  => $asientoId,
                ':detalles'     => json_encode(['numero_asiento' => $numeroAsiento, 'monto_total' => $totalDebe]),
                ':ip'           => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            $this->pdo->commit();
            return $asientoId;

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}