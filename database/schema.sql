-- =====================================================================
-- Sistema de Inspección de Edificaciones Post-Sismo
-- ESQUEMA COMPLETO Y ÚNICO (alcance NACIONAL + Seguimiento y Control)
-- =====================================================================
-- Este archivo reemplaza al antiguo schema.sql y a TODAS las migraciones
-- (actualizacion_v2..v7). Contiene la estructura final y consolidada.
--
-- Instalación nueva:
--   mysql -u root -p < database/schema.sql
--   mysql -u root -p < database/datos_iniciales.sql
--
-- Incluye:
--   - Núcleo: roles, módulos, permisos (RBAC), usuarios (con es_master /
--     estado_asignado para el alcance nacional), inspecciones (todos los
--     campos del instrumento), fotos, ingenieros, bitácora, configuración.
--   - Módulo de Seguimiento y Control: entes, seguimiento_obras,
--     seguimiento_recursos, seguimiento_fotos, seguimiento_bitacora.
-- =====================================================================


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `inspecciones_sismos` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;

USE `inspecciones_sismos`;
DROP TABLE IF EXISTS `entes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `entes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(180) NOT NULL,
  `tipo` enum('Gobernación','Alcaldía','Ministerio','Empresa Pública','Empresa Privada','ONG','Comunidad Organizada','Otro') NOT NULL DEFAULT 'Otro',
  `estado` varchar(100) DEFAULT NULL COMMENT 'Estado de operación (NULL = nacional)',
  `contacto_nombre` varchar(150) DEFAULT NULL,
  `contacto_telefono` varchar(30) DEFAULT NULL,
  `contacto_email` varchar(150) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ente_nombre` (`nombre`),
  KEY `idx_ente_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `envios_formulario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `envios_formulario` (
  `client_submission_id` varchar(64) NOT NULL,
  `inspeccion_id` int(10) unsigned NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`client_submission_id`),
  KEY `idx_envios_inspeccion` (`inspeccion_id`),
  CONSTRAINT `fk_envios_inspeccion` FOREIGN KEY (`inspeccion_id`) REFERENCES `inspecciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ingenieros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ingenieros` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre_completo` varchar(150) NOT NULL,
  `cedula` varchar(20) NOT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `profesion` varchar(100) DEFAULT NULL,
  `colegio_inscripcion` varchar(50) DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_por` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `cedula` (`cedula`),
  KEY `fk_ingeniero_creado_por` (`creado_por`),
  KEY `idx_ingeniero_activo` (`activo`),
  CONSTRAINT `fk_ingeniero_creado_por` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inspeccion_fotos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inspeccion_fotos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `inspeccion_id` int(10) unsigned NOT NULL,
  `categoria` varchar(60) NOT NULL,
  `ruta` varchar(255) NOT NULL,
  `nombre_original` varchar(255) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_foto_inspeccion` (`inspeccion_id`,`categoria`),
  CONSTRAINT `fk_foto_inspeccion` FOREIGN KEY (`inspeccion_id`) REFERENCES `inspecciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inspecciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inspecciones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `codigo` varchar(20) NOT NULL,
  `planilla_numero` varchar(30) DEFAULT NULL,
  `tipo_evento` varchar(150) DEFAULT NULL,
  `fecha_evento` date DEFAULT NULL,
  `ing1_nombre` varchar(150) NOT NULL,
  `ing1_cedula` varchar(20) NOT NULL,
  `ing1_telefono` varchar(20) DEFAULT NULL,
  `ing1_profesion` varchar(100) DEFAULT NULL,
  `ing1_inscripcion` varchar(50) DEFAULT NULL,
  `ing1_id` int(10) unsigned DEFAULT NULL,
  `ing2_nombre` varchar(150) DEFAULT NULL,
  `ing2_cedula` varchar(20) DEFAULT NULL,
  `ing2_telefono` varchar(20) DEFAULT NULL,
  `ing2_profesion` varchar(100) DEFAULT NULL,
  `ing2_inscripcion` varchar(50) DEFAULT NULL,
  `ing2_id` int(10) unsigned DEFAULT NULL,
  `nombre_edificio` varchar(200) NOT NULL,
  `fecha_inspeccion` date NOT NULL,
  `hora_inicio` time DEFAULT NULL,
  `hora_culminacion` time DEFAULT NULL,
  `cantidad_apartamentos` smallint(5) unsigned DEFAULT 0,
  `num_pisos` smallint(5) unsigned DEFAULT 0,
  `num_semisotanos` smallint(5) unsigned DEFAULT 0,
  `num_sotanos` smallint(5) unsigned DEFAULT 0,
  `anio_construccion` smallint(5) unsigned DEFAULT NULL,
  `numero_personas` int(10) unsigned DEFAULT NULL,
  `estado` varchar(100) DEFAULT NULL,
  `ciudad` varchar(100) DEFAULT NULL,
  `municipio` varchar(100) DEFAULT NULL,
  `parroquia` varchar(100) NOT NULL,
  `comuna_circuito` varchar(150) DEFAULT NULL,
  `urbanizacion` varchar(150) DEFAULT NULL,
  `sector` varchar(150) DEFAULT NULL,
  `avenida_calle` varchar(200) DEFAULT NULL,
  `nombre_comunidad` varchar(150) DEFAULT NULL,
  `coordenadas_utm` varchar(100) DEFAULT NULL,
  `huso` varchar(10) DEFAULT NULL,
  `latitud` decimal(11,7) DEFAULT NULL,
  `longitud` decimal(11,7) DEFAULT NULL,
  `uso_edificacion` varchar(100) DEFAULT NULL,
  `tipo_estructural` varchar(100) DEFAULT NULL,
  `material_acero` tinyint(1) NOT NULL DEFAULT 0,
  `material_concreto` tinyint(1) NOT NULL DEFAULT 0,
  `material_conexiones` tinyint(1) NOT NULL DEFAULT 0,
  `material_mamposteria` tinyint(1) DEFAULT 0,
  `mamposteria_formal` tinyint(1) NOT NULL DEFAULT 0,
  `mamposteria_informal` tinyint(1) NOT NULL DEFAULT 0,
  `material_otros` tinyint(1) NOT NULL DEFAULT 0,
  `material_otros_especifique` varchar(255) DEFAULT NULL,
  `colapso_estructura` enum('No','Parcial','Total') DEFAULT 'No',
  `riesgo_edificios_aledanos` varchar(20) DEFAULT NULL,
  `amenaza_geologica` varchar(20) DEFAULT NULL,
  `asentamiento_edificio` varchar(20) DEFAULT NULL,
  `inclinacion_edificio` varchar(20) DEFAULT NULL,
  `requiere_inspeccion_interna` enum('Si','No') DEFAULT 'No',
  `riesgo_externo` varchar(20) DEFAULT NULL,
  `pisos_inspeccionados` varchar(255) DEFAULT NULL,
  `acceso_miembros_estructurales` varchar(20) DEFAULT NULL,
  `piso_critico` varchar(100) DEFAULT NULL,
  `riesgo_estructural_severo` varchar(20) DEFAULT NULL,
  `elementos_piso_critico` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`elementos_piso_critico`)),
  `riesgo_estructural_moderado` varchar(20) DEFAULT NULL,
  `danos_estructurales` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`danos_estructurales`)),
  `requiere_intervencion` enum('Si','No') DEFAULT 'No',
  `pct_dano_iii` decimal(5,2) DEFAULT 0.00,
  `pct_dano_iv` decimal(5,2) DEFAULT 0.00,
  `pct_dano_v` decimal(5,2) DEFAULT 0.00,
  `danos_no_estructurales` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`danos_no_estructurales`)),
  `riesgo_componentes` varchar(20) DEFAULT NULL,
  `familias` int(10) unsigned NOT NULL DEFAULT 0,
  `ninos` int(10) unsigned NOT NULL DEFAULT 0,
  `mujeres` int(10) unsigned NOT NULL DEFAULT 0,
  `hombres` int(10) unsigned NOT NULL DEFAULT 0,
  `adultos_tercera_edad` int(10) unsigned NOT NULL DEFAULT 0,
  `gestantes` int(10) unsigned NOT NULL DEFAULT 0,
  `movilidad_reducida` int(10) unsigned NOT NULL DEFAULT 0,
  `mascotas` int(10) unsigned NOT NULL DEFAULT 0,
  `decision_final` enum('Edificación Inspeccionada - Acceso Permitido','Acceso Restringido - Precaución al Entrar','Edificación Insegura - Acceso No Permitido') NOT NULL DEFAULT 'Edificación Inspeccionada - Acceso Permitido',
  `inspeccion_previa_etiqueta` varchar(100) DEFAULT NULL,
  `inspeccion_especializada` varchar(20) DEFAULT NULL,
  `intervencion_de` varchar(150) DEFAULT NULL,
  `medidas_seguridad` text DEFAULT NULL,
  `m2_losas` decimal(10,2) DEFAULT NULL,
  `muros_reconstruir` int(10) unsigned DEFAULT NULL,
  `lugares_medidas` text DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `recomendaciones` text DEFAULT NULL,
  `acciones_recomendadas` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`acciones_recomendadas`)),
  `datos_adicionales` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`datos_adicionales`)),
  `tiene_tanque_agua` tinyint(1) DEFAULT NULL,
  `creado_por` int(10) unsigned DEFAULT NULL,
  `actualizado_por` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`),
  KEY `fk_insp_creado_por` (`creado_por`),
  KEY `fk_insp_actualizado_por` (`actualizado_por`),
  KEY `idx_parroquia` (`parroquia`),
  KEY `idx_decision` (`decision_final`),
  KEY `idx_fecha` (`fecha_inspeccion`),
  KEY `fk_insp_ing1` (`ing1_id`),
  KEY `fk_insp_ing2` (`ing2_id`),
  KEY `idx_estado` (`estado`),
  KEY `idx_estado_municipio` (`estado`,`municipio`),
  CONSTRAINT `fk_insp_actualizado_por` FOREIGN KEY (`actualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_insp_creado_por` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_insp_ing1` FOREIGN KEY (`ing1_id`) REFERENCES `ingenieros` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_insp_ing2` FOREIGN KEY (`ing2_id`) REFERENCES `ingenieros` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `log_actividad`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `log_actividad` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `accion` varchar(100) NOT NULL,
  `detalle` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_log_usuario` (`usuario_id`),
  CONSTRAINT `fk_log_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `modulos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `modulos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `clave` varchar(40) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `icono` varchar(60) DEFAULT NULL,
  `orden` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clave` (`clave`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `panel_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `panel_config` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `clave` varchar(60) NOT NULL,
  `valor` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`valor`)),
  `actualizado_por` int(10) unsigned DEFAULT NULL,
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `clave` (`clave`),
  KEY `fk_panelconfig_usuario` (`actualizado_por`),
  CONSTRAINT `fk_panelconfig_usuario` FOREIGN KEY (`actualizado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rol_modulo_permisos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rol_modulo_permisos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rol_id` int(10) unsigned NOT NULL,
  `modulo_id` int(10) unsigned NOT NULL,
  `ver` tinyint(1) NOT NULL DEFAULT 0,
  `crear` tinyint(1) NOT NULL DEFAULT 0,
  `editar` tinyint(1) NOT NULL DEFAULT 0,
  `eliminar` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rol_modulo` (`rol_id`,`modulo_id`),
  KEY `fk_rmp_modulo` (`modulo_id`),
  CONSTRAINT `fk_rmp_modulo` FOREIGN KEY (`modulo_id`) REFERENCES `modulos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rmp_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(60) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `es_sistema` tinyint(1) NOT NULL DEFAULT 0,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seguimiento_bitacora`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `seguimiento_bitacora` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `obra_id` int(10) unsigned NOT NULL,
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `evento` varchar(120) NOT NULL,
  `detalle` varchar(500) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_bit_user` (`usuario_id`),
  KEY `idx_bit_obra` (`obra_id`,`creado_en`),
  CONSTRAINT `fk_bit_obra` FOREIGN KEY (`obra_id`) REFERENCES `seguimiento_obras` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bit_user` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seguimiento_fotos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `seguimiento_fotos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `obra_id` int(10) unsigned NOT NULL,
  `fase` enum('Inicio','Avance','Culminada') NOT NULL DEFAULT 'Avance',
  `fecha_registro` date NOT NULL,
  `ruta` varchar(255) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `avance_pct` decimal(5,2) DEFAULT NULL COMMENT 'Avance declarado al momento de la foto',
  `subido_por` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_segfoto_user` (`subido_por`),
  KEY `idx_segfoto_obra` (`obra_id`,`fase`,`fecha_registro`),
  CONSTRAINT `fk_segfoto_obra` FOREIGN KEY (`obra_id`) REFERENCES `seguimiento_obras` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_segfoto_user` FOREIGN KEY (`subido_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seguimiento_obras`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `seguimiento_obras` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `inspeccion_id` int(10) unsigned NOT NULL,
  `ente_id` int(10) unsigned DEFAULT NULL,
  `responsable_id` int(10) unsigned DEFAULT NULL,
  `fecha_inicio` date DEFAULT NULL,
  `fecha_fin_estimada` date DEFAULT NULL,
  `fecha_fin_real` date DEFAULT NULL,
  `tiempo_accion_dias` int(10) unsigned DEFAULT NULL COMMENT 'Duración estimada de la reconstrucción en días',
  `estado_obra` enum('Sin iniciar','En ejecución','Suspendida','Culminada') NOT NULL DEFAULT 'Sin iniciar',
  `avance_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `presupuesto_estimado` decimal(14,2) DEFAULT NULL,
  `prioridad` enum('Alta','Media','Baja') NOT NULL DEFAULT 'Media',
  `observaciones` text DEFAULT NULL,
  `creado_por` int(10) unsigned DEFAULT NULL,
  `actualizado_por` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seg_inspeccion` (`inspeccion_id`),
  KEY `fk_seg_creado_por` (`creado_por`),
  KEY `idx_seg_estado_obra` (`estado_obra`),
  KEY `idx_seg_ente` (`ente_id`),
  KEY `idx_seg_responsable` (`responsable_id`),
  CONSTRAINT `fk_seg_creado_por` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_seg_ente` FOREIGN KEY (`ente_id`) REFERENCES `entes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_seg_inspeccion` FOREIGN KEY (`inspeccion_id`) REFERENCES `inspecciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_seg_responsable` FOREIGN KEY (`responsable_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seguimiento_recursos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `seguimiento_recursos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `obra_id` int(10) unsigned NOT NULL,
  `recurso` varchar(150) NOT NULL,
  `unidad` varchar(30) DEFAULT NULL,
  `cantidad_estimada` decimal(14,2) DEFAULT NULL,
  `cantidad_utilizada` decimal(14,2) NOT NULL DEFAULT 0.00,
  `origen` enum('Inspección','Manual') NOT NULL DEFAULT 'Manual',
  `nota` varchar(255) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rec_obra` (`obra_id`),
  CONSTRAINT `fk_rec_obra` FOREIGN KEY (`obra_id`) REFERENCES `seguimiento_obras` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre_completo` varchar(150) NOT NULL,
  `usuario` varchar(60) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `rol_id` int(10) unsigned NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `ultimo_acceso` datetime DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `es_master` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = acceso nacional (todos los estados)',
  `estado_asignado` varchar(100) DEFAULT NULL COMMENT 'Estado al que se limita el usuario si no es master',
  PRIMARY KEY (`id`),
  UNIQUE KEY `usuario` (`usuario`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_usuarios_rol` (`rol_id`),
  CONSTRAINT `fk_usuarios_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

