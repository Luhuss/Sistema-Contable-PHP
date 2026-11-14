-- ============================================================================
-- SISTEMA CONTABLE Y TRIBUTARIO WEB MODULAR (CHILE 2026 - NORMATIVA SII)
-- Archivo: sql/esquema.sql
-- Motor: MySQL 8.0+ (InnoDB) | Charset: utf8mb4_unicode_ci
-- Arquitectura: Multi-tenant (Plan base 1 Contador / 5 empresas),
--               BCMath DECIMAL(15,4), Inmutabilidad Contable Absoluta (ADR-002)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `sistema_contable_cl`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `sistema_contable_cl`;

-- ============================================================================
-- 1. TABLAS MAESTRAS Y DE SEGURIDAD
-- ============================================================================

DROP TABLE IF EXISTS `bitacora_auditoria`;
DROP TABLE IF EXISTS `libro_compras_ventas`;
DROP TABLE IF EXISTS `libro_honorarios`;
DROP TABLE IF EXISTS `detalle_asientos`;
DROP TABLE IF EXISTS `asientos`;
DROP TABLE IF EXISTS `periodos_fiscales`;
DROP TABLE IF EXISTS `plan_cuentas`;
DROP TABLE IF EXISTS `usuario_empresa`;
DROP TABLE IF EXISTS `usuarios`;
DROP TABLE IF EXISTS `empresas`;

CREATE TABLE `empresas` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rut` VARCHAR(12) NOT NULL COMMENT 'Formato limpio: 76123456-K',
    `razon_social` VARCHAR(200) NOT NULL,
    `nombre_fantasia` VARCHAR(150) DEFAULT NULL,
    `giro` VARCHAR(255) NOT NULL,
    `direccion` VARCHAR(255) DEFAULT NULL,
    `comuna` VARCHAR(100) DEFAULT NULL,
    `regimen_tributario` ENUM('PRO_PYME_GENERAL', 'PRO_PYME_TRANSPARENTE', 'GENERAL_14A', 'RENTA_PRESUNTA') NOT NULL DEFAULT 'PRO_PYME_GENERAL',
    `tasa_ppm` DECIMAL(6,4) NOT NULL DEFAULT 0.0025 COMMENT 'Tasa PPM vigente (ej. 0.0025 = 0.25%)',
    `activa` TINYINT(1) NOT NULL DEFAULT 1,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_empresas_rut` (`rut`),
    KEY `idx_empresas_activa` (`activa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `usuarios` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rut` VARCHAR(12) NOT NULL,
    `nombre_completo` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `rol` ENUM('ADMIN', 'CONTADOR', 'AUDITOR') NOT NULL DEFAULT 'CONTADOR',
    `activo` TINYINT(1) NOT NULL DEFAULT 1,
    `ultimo_acceso` DATETIME DEFAULT NULL,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_usuarios_rut` (`rut`),
    UNIQUE KEY `uk_usuarios_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `usuario_empresa` (
    `usuario_id` INT UNSIGNED NOT NULL,
    `empresa_id` INT UNSIGNED NOT NULL,
    `permiso` ENUM('LECTURA', 'ESCRITURA', 'ADMIN') NOT NULL DEFAULT 'ESCRITURA',
    `asignado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`usuario_id`, `empresa_id`),
    CONSTRAINT `fk_ue_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ue_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. PLAN DE CUENTAS Y PERÍODOS FISCALES (MULTI-TENANT)
-- ============================================================================

CREATE TABLE `plan_cuentas` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT UNSIGNED NOT NULL,
    `cuenta_padre_id` INT UNSIGNED DEFAULT NULL,
    `codigo` VARCHAR(30) NOT NULL COMMENT 'Ej: 1.1.01.001',
    `nombre` VARCHAR(150) NOT NULL,
    `tipo` ENUM('ACTIVO', 'PASIVO', 'PATRIMONIO', 'INGRESO', 'GASTO', 'COSTO') NOT NULL,
    `naturaleza` ENUM('DEUDORA', 'ACREEDORA') NOT NULL,
    `nivel` TINYINT UNSIGNED NOT NULL DEFAULT 4 COMMENT '1=Grupo, 2=Subgrupo, 3=Cuenta, 4=Imputable',
    `es_imputable` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Recibe asientos, 0=Cuenta agrupadora',
    `codigo_sii` VARCHAR(20) DEFAULT NULL COMMENT 'Homologación Diccionario SII / F22',
    `activa` TINYINT(1) NOT NULL DEFAULT 1,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_plan_empresa_codigo` (`empresa_id`, `codigo`),
    KEY `idx_plan_empresa_tipo` (`empresa_id`, `tipo`, `activa`),
    KEY `idx_plan_padre` (`cuenta_padre_id`),
    CONSTRAINT `fk_plan_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_plan_padre` FOREIGN KEY (`cuenta_padre_id`) REFERENCES `plan_cuentas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `periodos_fiscales` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT UNSIGNED NOT NULL,
    `anio` SMALLINT UNSIGNED NOT NULL,
    `mes` TINYINT UNSIGNED NOT NULL COMMENT '1..12',
    `estado` ENUM('ABIERTO', 'CERRADO') NOT NULL DEFAULT 'ABIERTO',
    `cerrado_por` INT UNSIGNED DEFAULT NULL,
    `cerrado_en` DATETIME DEFAULT NULL,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_periodo_empresa_anio_mes` (`empresa_id`, `anio`, `mes`),
    KEY `idx_periodo_empresa_estado` (`empresa_id`, `estado`),
    CONSTRAINT `fk_periodo_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_periodo_cerrado_por` FOREIGN KEY (`cerrado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_periodo_mes` CHECK (`mes` BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. MOTOR CONTABLE INMUTABLE (ADR-001 & ADR-002)
-- ============================================================================

CREATE TABLE `asientos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT UNSIGNED NOT NULL,
    `periodo_id` INT UNSIGNED NOT NULL,
    `numero_asiento` INT UNSIGNED NOT NULL COMMENT 'Correlativo único por empresa y período',
    `fecha` DATE NOT NULL,
    `glosa` VARCHAR(255) NOT NULL,
    `tipo_asiento` ENUM('NORMAL', 'APERTURA', 'CIERRE', 'CONTRAASIENTO') NOT NULL DEFAULT 'NORMAL',
    `asiento_referencia_id` INT UNSIGNED DEFAULT NULL COMMENT 'ID asiento anulado si es CONTRAASIENTO',
    `total_debe` DECIMAL(15,4) NOT NULL,
    `total_haber` DECIMAL(15,4) NOT NULL,
    `creado_por` INT UNSIGNED DEFAULT NULL,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_asientos_empresa_periodo_num` (`empresa_id`, `periodo_id`, `numero_asiento`),
    KEY `idx_asientos_empresa_fecha` (`empresa_id`, `fecha`),
    KEY `idx_asientos_periodo` (`empresa_id`, `periodo_id`),
    CONSTRAINT `fk_asientos_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_asientos_periodo` FOREIGN KEY (`periodo_id`) REFERENCES `periodos_fiscales` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_asientos_ref` FOREIGN KEY (`asiento_referencia_id`) REFERENCES `asientos` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_asientos_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_asientos_cuadratura` CHECK (`total_debe` = `total_haber` AND `total_debe` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `detalle_asientos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `asiento_id` INT UNSIGNED NOT NULL,
    `cuenta_id` INT UNSIGNED NOT NULL,
    `debe` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    `haber` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    `glosa_linea` VARCHAR(200) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_detalle_asiento` (`asiento_id`),
    KEY `idx_detalle_cuenta` (`cuenta_id`),
    CONSTRAINT `fk_detalle_asiento` FOREIGN KEY (`asiento_id`) REFERENCES `asientos` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_detalle_cuenta` FOREIGN KEY (`cuenta_id`) REFERENCES `plan_cuentas` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_detalle_unidireccional` CHECK (
        (`debe` > 0.0000 AND `haber` = 0.0000) OR
        (`haber` > 0.0000 AND `debe` = 0.0000)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. LIBROS TRIBUTARIOS AUXILIARES (SII CHILE 2026)
-- ============================================================================

CREATE TABLE `libro_honorarios` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT UNSIGNED NOT NULL,
    `periodo_id` INT UNSIGNED NOT NULL,
    `numero_boleta` BIGINT UNSIGNED NOT NULL,
    `rut_profesional` VARCHAR(12) NOT NULL,
    `razon_social` VARCHAR(200) NOT NULL,
    `fecha_emision` DATE NOT NULL,
    `monto_bruto` DECIMAL(15,4) NOT NULL,
    `monto_retencion` DECIMAL(15,4) NOT NULL,
    `monto_liquido` DECIMAL(15,4) NOT NULL,
    `tasa_retencion` DECIMAL(6,4) NOT NULL DEFAULT 0.1425 COMMENT 'Ley 21.133 año 2026: 14.25%',
    `modo_calculo` ENUM('BRUTO', 'LIQUIDO') NOT NULL,
    `glosa` VARCHAR(255) NOT NULL,
    `estado` ENUM('VIGENTE', 'ANULADA') NOT NULL DEFAULT 'VIGENTE',
    `asiento_id` INT UNSIGNED DEFAULT NULL,
    `creado_por` INT UNSIGNED NOT NULL,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_bhe_empresa_rut_numero` (`empresa_id`, `rut_profesional`, `numero_boleta`),
    KEY `idx_bhe_empresa_periodo` (`empresa_id`, `periodo_id`, `estado`),
    KEY `idx_bhe_asiento` (`asiento_id`),
    CONSTRAINT `fk_bhe_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_bhe_periodo` FOREIGN KEY (`periodo_id`) REFERENCES `periodos_fiscales` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_bhe_asiento` FOREIGN KEY (`asiento_id`) REFERENCES `asientos` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_bhe_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_bhe_cuadratura` CHECK (`monto_bruto` = `monto_retencion` + `monto_liquido`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `libro_compras_ventas` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT UNSIGNED NOT NULL,
    `periodo_id` INT UNSIGNED NOT NULL,
    `tipo_operacion` ENUM('COMPRA', 'VENTA') NOT NULL,
    `tipo_documento_sii` SMALLINT UNSIGNED NOT NULL COMMENT '33=Factura Afecta, 34=Exenta, 56=Nota Débito, 61=Nota Crédito',
    `folio` BIGINT UNSIGNED NOT NULL,
    `rut_contraparte` VARCHAR(12) NOT NULL,
    `razon_social_contraparte` VARCHAR(200) NOT NULL,
    `fecha_emision` DATE NOT NULL,
    `fecha_recepcion_sii` DATE DEFAULT NULL,
    `monto_exento` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    `monto_neto` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    `tasa_iva` DECIMAL(6,4) NOT NULL DEFAULT 0.1900,
    `monto_iva` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    `iva_no_recuperable` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    `codigo_impuesto_adicional` VARCHAR(10) DEFAULT NULL COMMENT 'Códigos SII: ILA, Combustibles, etc.',
    `monto_impuesto_adicional` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    `monto_total` DECIMAL(15,4) NOT NULL,
    `glosa` VARCHAR(255) NOT NULL,
    `estado` ENUM('VIGENTE', 'ANULADO') NOT NULL DEFAULT 'VIGENTE',
    `asiento_id` INT UNSIGNED DEFAULT NULL,
    `creado_por` INT UNSIGNED NOT NULL,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_rcv_empresa_op_doc_rut_folio` (`empresa_id`, `tipo_operacion`, `tipo_documento_sii`, `rut_contraparte`, `folio`),
    KEY `idx_rcv_empresa_periodo_op` (`empresa_id`, `periodo_id`, `tipo_operacion`, `estado`),
    KEY `idx_rcv_asiento` (`asiento_id`),
    CONSTRAINT `fk_rcv_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_rcv_periodo` FOREIGN KEY (`periodo_id`) REFERENCES `periodos_fiscales` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_rcv_asiento` FOREIGN KEY (`asiento_id`) REFERENCES `asientos` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_rcv_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_rcv_total` CHECK (
        `monto_total` = `monto_exento` + `monto_neto` + `monto_iva` + `iva_no_recuperable` + `monto_impuesto_adicional`
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. BITÁCORA DE AUDITORÍA INALTERABLE (ADR-002)
-- ============================================================================

CREATE TABLE `bitacora_auditoria` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empresa_id` INT UNSIGNED NOT NULL,
    `usuario_id` INT UNSIGNED DEFAULT NULL,
    `accion` VARCHAR(50) NOT NULL COMMENT 'INSERT, UPDATE_ANULACION, CIERRE_PERIODO, LOGIN, etc.',
    `tabla_afectada` VARCHAR(64) NOT NULL,
    `registro_id` BIGINT UNSIGNED NOT NULL,
    `detalles` JSON NOT NULL,
    `ip_origen` VARCHAR(45) NOT NULL,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_auditoria_empresa_fecha` (`empresa_id`, `creado_en`),
    KEY `idx_auditoria_tabla_reg` (`empresa_id`, `tabla_afectada`, `registro_id`),
    CONSTRAINT `fk_auditoria_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_auditoria_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. TRIGGERS DE INMUTABILIDAD CONTABLE Y AUDITORÍA (ADR-002)
-- ============================================================================

DELIMITER $$

-- 6.1 Prohibir UPDATE o DELETE sobre `asientos`
CREATE TRIGGER `trg_asientos_no_update`
BEFORE UPDATE ON `asientos`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ADR-002 VIOLACION: Los asientos contables son inmutables. Emita un contraasiento.';
END$$

CREATE TRIGGER `trg_asientos_no_delete`
BEFORE DELETE ON `asientos`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ADR-002 VIOLACION: Prohibido eliminar asientos contables.';
END$$

-- 6.2 Prohibir UPDATE o DELETE sobre `detalle_asientos`
CREATE TRIGGER `trg_detalle_asientos_no_update`
BEFORE UPDATE ON `detalle_asientos`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ADR-002 VIOLACION: El detalle de asientos contables es inmutable.';
END$$

CREATE TRIGGER `trg_detalle_asientos_no_delete`
BEFORE DELETE ON `detalle_asientos`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ADR-002 VIOLACION: Prohibido eliminar lineas de asientos contables.';
END$$

-- 6.3 Proteger `libro_honorarios`: solo permite vincular asiento_id inicial o anular (VIGENTE -> ANULADA)
CREATE TRIGGER `trg_bhe_inmutabilidad_update`
BEFORE UPDATE ON `libro_honorarios`
FOR EACH ROW
BEGIN
    IF OLD.empresa_id <> NEW.empresa_id
        OR OLD.periodo_id <> NEW.periodo_id
        OR OLD.numero_boleta <> NEW.numero_boleta
        OR OLD.rut_profesional <> NEW.rut_profesional
        OR OLD.monto_bruto <> NEW.monto_bruto
        OR OLD.monto_retencion <> NEW.monto_retencion
        OR OLD.monto_liquido <> NEW.monto_liquido
        OR (OLD.asiento_id IS NOT NULL AND OLD.asiento_id <> NEW.asiento_id)
        OR (OLD.estado = 'ANULADA') THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ADR-002 VIOLACION: No se pueden alterar montos, identidad ni reabrir una BHE anulada.';
    END IF;
END$$

CREATE TRIGGER `trg_bhe_no_delete`
BEFORE DELETE ON `libro_honorarios`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ADR-002 VIOLACION: Prohibido eliminar registros del Libro de Honorarios.';
END$$

-- 6.4 Proteger `libro_compras_ventas`: solo permite vincular asiento_id inicial o anular (VIGENTE -> ANULADO)
CREATE TRIGGER `trg_rcv_inmutabilidad_update`
BEFORE UPDATE ON `libro_compras_ventas`
FOR EACH ROW
BEGIN
    IF OLD.empresa_id <> NEW.empresa_id
        OR OLD.periodo_id <> NEW.periodo_id
        OR OLD.tipo_operacion <> NEW.tipo_operacion
        OR OLD.tipo_documento_sii <> NEW.tipo_documento_sii
        OR OLD.folio <> NEW.folio
        OR OLD.rut_contraparte <> NEW.rut_contraparte
        OR OLD.monto_exento <> NEW.monto_exento
        OR OLD.monto_neto <> NEW.monto_neto
        OR OLD.monto_iva <> NEW.monto_iva
        OR OLD.iva_no_recuperable <> NEW.iva_no_recuperable
        OR OLD.monto_impuesto_adicional <> NEW.monto_impuesto_adicional
        OR OLD.monto_total <> NEW.monto_total
        OR (OLD.asiento_id IS NOT NULL AND OLD.asiento_id <> NEW.asiento_id)
        OR (OLD.estado = 'ANULADO') THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ADR-002 VIOLACION: No se pueden alterar montos ni reabrir documentos en Compras/Ventas.';
    END IF;
END$$

CREATE TRIGGER `trg_rcv_no_delete`
BEFORE DELETE ON `libro_compras_ventas`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ADR-002 VIOLACION: Prohibido eliminar registros del Libro de Compras y Ventas.';
END$$

-- 6.5 Prohibir UPDATE o DELETE sobre `bitacora_auditoria`
CREATE TRIGGER `trg_auditoria_no_update`
BEFORE UPDATE ON `bitacora_auditoria`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ADR-002 VIOLACION: La bitacora de auditoria es inalterable.';
END$$

CREATE TRIGGER `trg_auditoria_no_delete`
BEFORE DELETE ON `bitacora_auditoria`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ADR-002 VIOLACION: Prohibido eliminar registros de la bitacora de auditoria.';
END$$

DELIMITER ;
SET FOREIGN_KEY_CHECKS = 1;