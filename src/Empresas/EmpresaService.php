<?php

declare(strict_types=1);

require_once __DIR__ . '/../Auditoria/AuditoriaService.php';

/**
 * Servicio de Gestión de Cartera de Empresas (Multi-tenancy para Contador).
 *
 * Administra las empresas clientes operadas por el Contador titular,
 * aplicando el límite de cupo contratado en la licencia (Plan Base: 5 empresas),
 * validación de RUT chileno (Módulo 11 con ctype_digit), regímenes tributarios
 * SII y tasa PPM con precisión BCMath.
 */
class EmpresaService
{
    private const REGIMENES_VALIDOS = [
        'PRO_PYME_GENERAL',
        'PRO_PYME_TRANSPARENTE',
        'GENERAL_14A',
        'RENTA_PRESUNTA',
    ];

    private PDO $pdo;
    private AuditoriaService $auditoriaService;
    private int $maxEmpresasPermitidas;

    public function __construct(PDO $pdo, AuditoriaService $auditoriaService, ?int $maxEmpresasPermitidas = null)
    {
        $this->pdo = $pdo;
        $this->auditoriaService = $auditoriaService;
        $this->maxEmpresasPermitidas = $maxEmpresasPermitidas
            ?? (defined('MAX_EMPRESAS_TENANT') ? (int)MAX_EMPRESAS_TENANT : 5);
    }

    /**
     * Registra una nueva empresa en la cartera del Contador validando el cupo
     * máximo de su licencia, RUT Módulo 11 y parámetros tributarios de `empresas`.
     *
     * @param array $datos     ['rut', 'razon_social', 'nombre_fantasia', 'giro', 'direccion', 'comuna', 'regimen_tributario', 'tasa_ppm']
     * @param int   $usuarioId ID del Contador que ejecuta la creación
     * @return int ID de la empresa creada
     * @throws InvalidArgumentException|RuntimeException
     */
    public function crearEmpresa(array $datos, int $usuarioId): int
    {
        $rutNormalizado = $this->normalizarYValidarRut((string)($datos['rut'] ?? ''));

        $razonSocial = trim((string)($datos['razon_social'] ?? ''));
        if ($razonSocial === '' || mb_strlen($razonSocial) > 200) {
            throw new InvalidArgumentException('La razón social es obligatoria y no puede superar los 200 caracteres.');
        }

        $nombreFantasia = isset($datos['nombre_fantasia']) && trim((string)$datos['nombre_fantasia']) !== ''
            ? mb_substr(trim((string)$datos['nombre_fantasia']), 0, 150)
            : null;

        $giro = trim((string)($datos['giro'] ?? ''));
        if ($giro === '' || mb_strlen($giro) > 255) {
            throw new InvalidArgumentException('El giro comercial es obligatorio y no puede superar los 255 caracteres.');
        }

        $direccion = isset($datos['direccion']) && trim((string)$datos['direccion']) !== ''
            ? mb_substr(trim((string)$datos['direccion']), 0, 255)
            : null;

        $comuna = isset($datos['comuna']) && trim((string)$datos['comuna']) !== ''
            ? mb_substr(trim((string)$datos['comuna']), 0, 100)
            : null;

        $regimen = strtoupper(trim((string)($datos['regimen_tributario'] ?? 'PRO_PYME_GENERAL')));
        if (!in_array($regimen, self::REGIMENES_VALIDOS, true)) {
            throw new InvalidArgumentException("Régimen tributario inválido: '{$regimen}'.");
        }

        $tasaPpm = $this->validarTasaPpm((string)($datos['tasa_ppm'] ?? '0.0025'));

        $transaccionPropia = !$this->pdo->inTransaction();
        if ($transaccionPropia) {
            $this->pdo->beginTransaction();
        }

        try {
            // 1. Verificar cupo total de empresas contratadas en la licencia con bloqueo FOR UPDATE
            $stmtCount = $this->pdo->query("SELECT COUNT(*) FROM empresas FOR UPDATE");
            $totalRegistradas = (int)$stmtCount->fetchColumn();

            if ($totalRegistradas >= $this->maxEmpresasPermitidas) {
                throw new RuntimeException(
                    "Límite de plan alcanzado: Su licencia permite gestionar un máximo de {$this->maxEmpresasPermitidas} empresas."
                );
            }

            // 2. Verificar que el RUT no exista previamente
            $stmtExiste = $this->pdo->prepare("SELECT id FROM empresas WHERE rut = :rut LIMIT 1");
            $stmtExiste->execute([':rut' => $rutNormalizado]);
            if ($stmtExiste->fetchColumn() !== false) {
                throw new RuntimeException("Ya existe una empresa registrada con el RUT {$rutNormalizado}.");
            }

            // 3. Insertar empresa alineada con todas las columnas de `empresas`
            $sqlInsert = "INSERT INTO empresas (
                                rut,
                                razon_social,
                                nombre_fantasia,
                                giro,
                                direccion,
                                comuna,
                                regimen_tributario,
                                tasa_ppm,
                                activa
                            ) VALUES (
                                :rut,
                                :razon_social,
                                :nombre_fantasia,
                                :giro,
                                :direccion,
                                :comuna,
                                :regimen_tributario,
                                :tasa_ppm,
                                1
                            )";

            $stmtInsert = $this->pdo->prepare($sqlInsert);
            $stmtInsert->execute([
                ':rut'                => $rutNormalizado,
                ':razon_social'       => $razonSocial,
                ':nombre_fantasia'    => $nombreFantasia,
                ':giro'               => $giro,
                ':direccion'          => $direccion,
                ':comuna'             => $comuna,
                ':regimen_tributario' => $regimen,
                ':tasa_ppm'           => $tasaPpm,
            ]);

            $empresaId = (int)$this->pdo->lastInsertId();

            // 4. Registrar trazabilidad en bitacora_auditoria
            $this->auditoriaService->registrarEvento(
                $empresaId,
                'INSERT',
                'empresas',
                $empresaId,
                [
                    'rut'                => $rutNormalizado,
                    'razon_social'       => $razonSocial,
                    'regimen_tributario' => $regimen,
                    'tasa_ppm'           => $tasaPpm,
                    'cupo_usado'         => ($totalRegistradas + 1) . '/' . $this->maxEmpresasPermitidas,
                    'usuario_id'         => $usuarioId,
                ]
            );

            if ($transaccionPropia) {
                $this->pdo->commit();
            }

            return $empresaId;
        } catch (Throwable $e) {
            if ($transaccionPropia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Error al registrar la empresa: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Retorna el estado actual del cupo de empresas según la licencia contratada.
     *
     * @return array{usadas: int, maximo: int, disponibles: int, puede_crear: bool}
     */
    public function obtenerEstadoCupoLicencia(): array
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM empresas");
        $usadas = (int)$stmt->fetchColumn();
        $disponibles = max(0, $this->maxEmpresasPermitidas - $usadas);

        return [
            'usadas'      => $usadas,
            'maximo'      => $this->maxEmpresasPermitidas,
            'disponibles' => $disponibles,
            'puede_crear' => $usadas < $this->maxEmpresasPermitidas,
        ];
    }

    /**
     * Actualiza los datos administrativos y tributarios de una empresa (nunca el RUT).
     *
     * @param int   $empresaId ID de la empresa
     * @param array $datos     ['razon_social', 'nombre_fantasia', 'giro', 'direccion', 'comuna', 'regimen_tributario', 'tasa_ppm']
     * @param int   $usuarioId ID del usuario que modifica
     * @return void
     * @throws InvalidArgumentException|RuntimeException
     */
    public function actualizarDatosAdministrativos(int $empresaId, array $datos, int $usuarioId): void
    {
        $empresaActual = $this->obtenerPorId($empresaId);
        if (!$empresaActual) {
            throw new RuntimeException("La empresa ID {$empresaId} no existe.");
        }

        $razonSocial = trim((string)($datos['razon_social'] ?? $empresaActual['razon_social']));
        $giro        = trim((string)($datos['giro'] ?? $empresaActual['giro']));

        if ($razonSocial === '' || $giro === '') {
            throw new InvalidArgumentException('La razón social y el giro comercial son obligatorios.');
        }

        $nombreFantasia = array_key_exists('nombre_fantasia', $datos)
            ? (trim((string)$datos['nombre_fantasia']) !== '' ? mb_substr(trim((string)$datos['nombre_fantasia']), 0, 150) : null)
            : $empresaActual['nombre_fantasia'];

        $direccion = array_key_exists('direccion', $datos)
            ? (trim((string)$datos['direccion']) !== '' ? mb_substr(trim((string)$datos['direccion']), 0, 255) : null)
            : $empresaActual['direccion'];

        $comuna = array_key_exists('comuna', $datos)
            ? (trim((string)$datos['comuna']) !== '' ? mb_substr(trim((string)$datos['comuna']), 0, 100) : null)
            : $empresaActual['comuna'];

        $regimen = isset($datos['regimen_tributario'])
            ? strtoupper(trim((string)$datos['regimen_tributario']))
            : (string)$empresaActual['regimen_tributario'];

        if (!in_array($regimen, self::REGIMENES_VALIDOS, true)) {
            throw new InvalidArgumentException("Régimen tributario inválido: '{$regimen}'.");
        }

        $tasaPpm = isset($datos['tasa_ppm'])
            ? $this->validarTasaPpm((string)$datos['tasa_ppm'])
            : (string)$empresaActual['tasa_ppm'];

        $transaccionPropia = !$this->pdo->inTransaction();
        if ($transaccionPropia) {
            $this->pdo->beginTransaction();
        }

        try {
            $sql = "UPDATE empresas
                    SET razon_social = :razon_social,
                        nombre_fantasia = :nombre_fantasia,
                        giro = :giro,
                        direccion = :direccion,
                        comuna = :comuna,
                        regimen_tributario = :regimen_tributario,
                        tasa_ppm = :tasa_ppm,
                        actualizado_en = NOW()
                    WHERE id = :id";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':razon_social'       => mb_substr($razonSocial, 0, 200),
                ':nombre_fantasia'    => $nombreFantasia,
                ':giro'               => mb_substr($giro, 0, 255),
                ':direccion'          => $direccion,
                ':comuna'             => $comuna,
                ':regimen_tributario' => $regimen,
                ':tasa_ppm'           => $tasaPpm,
                ':id'                 => $empresaId,
            ]);

            $this->auditoriaService->registrarEvento(
                $empresaId,
                'CONFIGURACION',
                'empresas',
                $empresaId,
                [
                    'razon_social_anterior' => $empresaActual['razon_social'],
                    'razon_social_nueva'    => $razonSocial,
                    'regimen_tributario'    => $regimen,
                    'tasa_ppm'              => $tasaPpm,
                    'usuario_id'            => $usuarioId,
                ]
            );

            if ($transaccionPropia) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($transaccionPropia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Error al actualizar datos de la empresa: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Activa o pausa operativamente una empresa de la cartera del Contador.
     *
     * @param int  $empresaId ID de la empresa
     * @param bool $activa    true para activar, false para pausar
     * @param int  $usuarioId ID del usuario que ejecuta el cambio
     * @return void
     * @throws RuntimeException
     */
    public function cambiarEstadoActiva(int $empresaId, bool $activa, int $usuarioId): void
    {
        $empresa = $this->obtenerPorId($empresaId);
        if (!$empresa) {
            throw new RuntimeException("La empresa ID {$empresaId} no existe.");
        }

        $nuevoEstado = $activa ? 1 : 0;
        if ((int)$empresa['activa'] === $nuevoEstado) {
            return;
        }

        $transaccionPropia = !$this->pdo->inTransaction();
        if ($transaccionPropia) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare("UPDATE empresas SET activa = :activa, actualizado_en = NOW() WHERE id = :id");
            $stmt->execute([
                ':activa' => $nuevoEstado,
                ':id'     => $empresaId,
            ]);

            $this->auditoriaService->registrarEvento(
                $empresaId,
                'CONFIGURACION',
                'empresas',
                $empresaId,
                [
                    'accion_estado' => $activa ? 'ACTIVACION' : 'PAUSA_OPERATIVA',
                    'rut'           => $empresa['rut'],
                    'usuario_id'    => $usuarioId,
                ]
            );

            if ($transaccionPropia) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($transaccionPropia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException('Error al cambiar estado de la empresa: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Obtiene una empresa por su ID.
     *
     * @param int $empresaId
     * @return array<string, mixed>|null
     */
    public function obtenerPorId(int $empresaId): ?array
    {
        if ($empresaId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT id, rut, razon_social, nombre_fantasia, giro, direccion, comuna,
                    regimen_tributario, tasa_ppm, activa, creado_en, actualizado_en
            FROM empresas
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $empresaId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila ?: null;
    }

    /**
     * Lista las empresas registradas en la cartera del Contador.
     *
     * @param bool $soloActivas Si es true, filtra únicamente las activas
     * @return array<int, array<string, mixed>>
     */
    public function listarEmpresas(bool $soloActivas = true): array
    {
        $sql = "SELECT id, rut, razon_social, nombre_fantasia, giro, direccion, comuna,
                        regimen_tributario, tasa_ppm, activa, creado_en
                FROM empresas";

        if ($soloActivas) {
            $sql .= " WHERE activa = 1";
        }

        $sql .= " ORDER BY razon_social ASC";

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Valida y formatea la tasa PPM a 4 decimales BCMath (ej. 0.0025 = 0.25%).
     *
     * @param string $tasaRaw
     * @return string
     */
    private function validarTasaPpm(string $tasaRaw): string
    {
        $tasa = trim($tasaRaw);
        if (!is_numeric($tasa)) {
            throw new InvalidArgumentException("La tasa PPM '{$tasaRaw}' debe ser un valor numérico.");
        }

        $tasaClean = bcadd($tasa, '0', 4);
        if (bccomp($tasaClean, '0.0000', 4) < 0 || bccomp($tasaClean, '0.5000', 4) > 0) {
            throw new InvalidArgumentException("La tasa PPM debe estar entre 0.0000 (0%) y 0.5000 (50%).");
        }

        return $tasaClean;
    }

    /**
     * Sanitiza, valida mediante Módulo 11 (con ctype_digit) y retorna el RUT en formato canónico '12345678-9'.
     *
     * @param string $rutRaw
     * @return string
     * @throws InvalidArgumentException Si el RUT es inválido.
     */
    public function normalizarYValidarRut(string $rutRaw): string
    {
        $limpio = strtoupper((string)preg_replace('/[^0-9kK]/', '', trim($rutRaw)));

        if (strlen($limpio) < 7 || strlen($limpio) > 9) {
            throw new InvalidArgumentException("El RUT '{$rutRaw}' no cumple con la longitud válida en Chile (7 a 8 dígitos más DV).");
        }

        $cuerpo = substr($limpio, 0, -1);
        $dvIngresado = substr($limpio, -1);

        if (!ctype_digit($cuerpo)) {
            throw new InvalidArgumentException("El cuerpo del RUT '{$rutRaw}' contiene caracteres no numéricos.");
        }

        if ((int)$cuerpo <= 0) {
            throw new InvalidArgumentException("El número de RUT '{$rutRaw}' no es válido.");
        }

        $suma = 0;
        $multiplicador = 2;

        for ($i = strlen($cuerpo) - 1; $i >= 0; $i--) {
            $suma += ((int)$cuerpo[$i]) * $multiplicador;
            $multiplicador = ($multiplicador === 7) ? 2 : $multiplicador + 1;
        }

        $resto = $suma % 11;
        $resultado = 11 - $resto;

        $dvEsperado = match ($resultado) {
            11      => '0',
            10      => 'K',
            default => (string)$resultado,
        };

        if ($dvIngresado !== $dvEsperado) {
            throw new InvalidArgumentException("El dígito verificador del RUT '{$rutRaw}' es incorrecto.");
        }

        return $cuerpo . '-' . $dvEsperado;
    }
}