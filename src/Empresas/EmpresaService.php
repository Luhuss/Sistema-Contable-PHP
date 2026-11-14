<?php

declare(strict_types=1);

require_once __DIR__ . '/../Auditoria/AuditoriaService.php';

/**
 * Servicio de Gestión de Empresas (Multi-tenancy).
 *
 * Administra el ciclo de vida de hasta 5 empresas activas por instancia,
 * aplicando validación tributaria de RUT chileno (Módulo 11 con ctype_digit),
 * bloqueo concurrente en creación y trazabilidad inmutable en AuditoriaService.
 */
class EmpresaService
{
    private const MAX_EMPRESAS_ACTIVAS = 5;

    private PDO $pdo;
    private AuditoriaService $auditoriaService;

    public function __construct(PDO $pdo, AuditoriaService $auditoriaService)
    {
        $this->pdo = $pdo;
        $this->auditoriaService = $auditoriaService;
    }

    /**
     * Registra una nueva empresa validando el límite multi-tenant (máx. 5 activas),
     * formato de RUT chileno Módulo 11 y unicidad tributaria.
     *
     * @param array $datos     ['rut' => string, 'razon_social' => string, 'giro' => string, 'direccion' => ?string, 'comuna' => ?string, 'regimen_tributario' => ?string]
     * @param int   $usuarioId ID del usuario que ejecuta la creación
     * @return int ID de la empresa creada
     * @throws InvalidArgumentException Si los datos o el RUT son inválidos.
     * @throws RuntimeException Si se excede el límite de 5 empresas activas o el RUT ya existe.
     */
    public function crearEmpresa(array $datos, int $usuarioId): int
    {
        $rutNormalizado = $this->normalizarYValidarRut((string)($datos['rut'] ?? ''));

        $razonSocial = trim((string)($datos['razon_social'] ?? ''));
        if ($razonSocial === '' || mb_strlen($razonSocial) > 200) {
            throw new InvalidArgumentException('La razón social es obligatoria y no puede superar los 200 caracteres.');
        }

        $giro = trim((string)($datos['giro'] ?? ''));
        if ($giro === '' || mb_strlen($giro) > 200) {
            throw new InvalidArgumentException('El giro comercial es obligatorio y no puede superar los 200 caracteres.');
        }

        $direccion = isset($datos['direccion']) && trim((string)$datos['direccion']) !== ''
            ? mb_substr(trim((string)$datos['direccion']), 0, 255)
            : null;

        $comuna = isset($datos['comuna']) && trim((string)$datos['comuna']) !== ''
            ? mb_substr(trim((string)$datos['comuna']), 0, 100)
            : null;

        $regimen = isset($datos['regimen_tributario']) && trim((string)$datos['regimen_tributario']) !== ''
            ? mb_substr(trim((string)$datos['regimen_tributario']), 0, 80)
            : '14 D N°3 PRO PYME GENERAL';

        $transaccionPropia = !$this->pdo->inTransaction();
        if ($transaccionPropia) {
            $this->pdo->beginTransaction();
        }

        try {
            // 1. Verificar límite estricto de 5 empresas activas con bloqueo FOR UPDATE
            $stmtCount = $this->pdo->query("SELECT COUNT(*) FROM empresas WHERE activa = 1 FOR UPDATE");
            $totalActivas = (int)$stmtCount->fetchColumn();

            if ($totalActivas >= self::MAX_EMPRESAS_ACTIVAS) {
                throw new RuntimeException(
                    'Límite multi-tenant alcanzado: El sistema soporta un máximo de ' . self::MAX_EMPRESAS_ACTIVAS . ' empresas activas.'
                );
            }

            // 2. Verificar que el RUT no exista previamente
            $stmtExiste = $this->pdo->prepare("SELECT id FROM empresas WHERE rut = :rut LIMIT 1");
            $stmtExiste->execute([':rut' => $rutNormalizado]);
            if ($stmtExiste->fetchColumn() !== false) {
                throw new RuntimeException("Ya existe una empresa registrada con el RUT {$rutNormalizado}.");
            }

            // 3. Insertar empresa
            $sqlInsert = "INSERT INTO empresas (
                                rut,
                                razon_social,
                                giro,
                                direccion,
                                comuna,
                                regimen_tributario,
                                activa
                            ) VALUES (
                                :rut,
                                :razon_social,
                                :giro,
                                :direccion,
                                :comuna,
                                :regimen_tributario,
                                1
                            )";

            $stmtInsert = $this->pdo->prepare($sqlInsert);
            $stmtInsert->execute([
                ':rut'                => $rutNormalizado,
                ':razon_social'       => $razonSocial,
                ':giro'               => $giro,
                ':direccion'          => $direccion,
                ':comuna'             => $comuna,
                ':regimen_tributario' => $regimen,
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
     * Actualiza los datos administrativos permitidos de una empresa (nunca el RUT).
     *
     * @param int   $empresaId ID de la empresa
     * @param array $datos     ['razon_social', 'giro', 'direccion', 'comuna', 'regimen_tributario']
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

        $direccion = isset($datos['direccion']) && trim((string)$datos['direccion']) !== ''
            ? mb_substr(trim((string)$datos['direccion']), 0, 255)
            : null;

        $comuna = isset($datos['comuna']) && trim((string)$datos['comuna']) !== ''
            ? mb_substr(trim((string)$datos['comuna']), 0, 100)
            : null;

        $regimen = isset($datos['regimen_tributario']) && trim((string)$datos['regimen_tributario']) !== ''
            ? mb_substr(trim((string)$datos['regimen_tributario']), 0, 80)
            : (string)$empresaActual['regimen_tributario'];

        $transaccionPropia = !$this->pdo->inTransaction();
        if ($transaccionPropia) {
            $this->pdo->beginTransaction();
        }

        try {
            $sql = "UPDATE empresas
                    SET razon_social = :razon_social,
                        giro = :giro,
                        direccion = :direccion,
                        comuna = :comuna,
                        regimen_tributario = :regimen_tributario,
                        actualizado_en = NOW()
                    WHERE id = :id";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':razon_social'       => mb_substr($razonSocial, 0, 200),
                ':giro'               => mb_substr($giro, 0, 200),
                ':direccion'          => $direccion,
                ':comuna'             => $comuna,
                ':regimen_tributario' => $regimen,
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
            throw new RuntimeException('Error al actualizar datos de la empresa: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Cambia el estado de activación de una empresa respetando el tope máximo de 5 activas.
     *
     * @param int  $empresaId ID de la empresa
     * @param bool $activa    true para activar, false para desactivar
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
            if ($activa) {
                $stmtCount = $this->pdo->query("SELECT COUNT(*) FROM empresas WHERE activa = 1 FOR UPDATE");
                if ((int)$stmtCount->fetchColumn() >= self::MAX_EMPRESAS_ACTIVAS) {
                    throw new RuntimeException(
                        'No es posible reactivar la empresa: ya existen ' . self::MAX_EMPRESAS_ACTIVAS . ' empresas activas.'
                    );
                }
            }

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
                    'accion_estado' => $activa ? 'ACTIVACION' : 'DESACTIVACION',
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
            if ($e instanceof RuntimeException) {
                throw $e;
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
            SELECT id, rut, razon_social, giro, direccion, comuna, regimen_tributario, activa, creado_en, actualizado_en
            FROM empresas
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $empresaId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila ?: null;
    }

    /**
     * Lista las empresas registradas en el sistema.
     *
     * @param bool $soloActivas Si es true, filtra únicamente las activas (máx. 5)
     * @return array<int, array<string, mixed>>
     */
    public function listarEmpresas(bool $soloActivas = true): array
    {
        $sql = "SELECT id, rut, razon_social, giro, direccion, comuna, regimen_tributario, activa, creado_en
                FROM empresas";

        if ($soloActivas) {
            $sql .= " WHERE activa = 1";
        }

        $sql .= " ORDER BY razon_social ASC";

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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