#!/bin/bash
################################################################################
# Script de diagnóstico para problemas de asynchronous_copy_task
# Entorno: PHP 8.1 + LiteSpeed + CloudLinux
# Autor: Claude Code
################################################################################

echo "=============================================================================="
echo "DIAGNÓSTICO DE ASYNC COPY TASK - MOODLE + LITESPEED + CLOUDLINUX"
echo "=============================================================================="
echo ""

# Colores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Detectar ruta de Moodle
MOODLE_PATH="/home/ingeniusvirtual/public_html"
if [ ! -f "$MOODLE_PATH/config.php" ]; then
    echo -e "${RED}ERROR: No se encuentra Moodle en $MOODLE_PATH${NC}"
    read -p "Ingresa la ruta completa de Moodle: " MOODLE_PATH
fi

# Detectar usuario actual
CURRENT_USER=$(whoami)
echo -e "${GREEN}Usuario actual: $CURRENT_USER${NC}"
echo -e "${GREEN}Ruta Moodle: $MOODLE_PATH${NC}"
echo ""

################################################################################
# 1. INFORMACIÓN DEL SISTEMA
################################################################################
echo "=============================================================================="
echo "1. INFORMACIÓN DEL SISTEMA"
echo "=============================================================================="

echo -e "\n${YELLOW}--- Versión PHP CLI ---${NC}"
/opt/cpanel/ea-php81/root/usr/bin/php -v 2>/dev/null || php -v

echo -e "\n${YELLOW}--- Configuración PHP relevante ---${NC}"
/opt/cpanel/ea-php81/root/usr/bin/php -i 2>/dev/null | grep -E "max_execution_time|memory_limit|max_input_time|post_max_size|upload_max_filesize" || \
php -i | grep -E "max_execution_time|memory_limit|max_input_time|post_max_size|upload_max_filesize"

echo -e "\n${YELLOW}--- CloudLinux LVE Info ---${NC}"
if command -v lvectl &> /dev/null; then
    echo "CloudLinux detectado"
    lvectl list 2>/dev/null | head -20
else
    echo "CloudLinux no detectado o sin permisos"
fi

echo -e "\n${YELLOW}--- Espacio en disco ---${NC}"
df -h "$MOODLE_PATH" | tail -1
df -h /home | grep -E "Filesystem|/home"

################################################################################
# 2. ESTADO DE TAREAS ADHOC
################################################################################
echo ""
echo "=============================================================================="
echo "2. ESTADO DE TAREAS ADHOC EN BASE DE DATOS"
echo "=============================================================================="

# Intentar leer credenciales de config.php
if [ -f "$MOODLE_PATH/config.php" ]; then
    DB_HOST=$(grep '$CFG->dbhost' "$MOODLE_PATH/config.php" | cut -d"'" -f2 | head -1)
    DB_NAME=$(grep '$CFG->dbname' "$MOODLE_PATH/config.php" | cut -d"'" -f2 | head -1)
    DB_USER=$(grep '$CFG->dbuser' "$MOODLE_PATH/config.php" | cut -d"'" -f2 | head -1)
    DB_PREFIX=$(grep '$CFG->prefix' "$MOODLE_PATH/config.php" | cut -d"'" -f2 | head -1 || echo "mdl_")

    echo -e "${GREEN}DB detectada: $DB_NAME@$DB_HOST (usuario: $DB_USER, prefijo: $DB_PREFIX)${NC}"
    echo ""

    # Crear archivo SQL temporal
    TMP_SQL="/tmp/moodle_diag_$$.sql"

    cat > "$TMP_SQL" << EOF
-- Tareas async copy pendientes
SELECT 'TAREAS ASYNC COPY PENDIENTES/CORRIENDO:' as info;
SELECT id, classname, faildelay,
       FROM_UNIXTIME(nextruntime) as proxima_ejecucion,
       FROM_UNIXTIME(timestarted) as inicio,
       CASE WHEN timestarted IS NOT NULL
            THEN TIMESTAMPDIFF(MINUTE, FROM_UNIXTIME(timestarted), NOW())
            ELSE NULL END as minutos_corriendo
FROM ${DB_PREFIX}task_adhoc
WHERE classname = '\\\\core\\\\task\\\\asynchronous_copy_task'
ORDER BY id DESC LIMIT 10;

-- Controladores backup/restore
SELECT '\nCONTROLADORES BACKUP/RESTORE ACTIVOS:' as info;
SELECT id, operation, status, execution,
       FROM_UNIXTIME(timecreated) as creado,
       FROM_UNIXTIME(timemodified) as modificado,
       TIMESTAMPDIFF(MINUTE, FROM_UNIXTIME(timecreated), NOW()) as edad_minutos
FROM ${DB_PREFIX}backup_controllers
WHERE status != 800
ORDER BY timecreated DESC LIMIT 10;

-- Estadísticas generales
SELECT '\nESTADÍSTICAS GENERALES:' as info;
SELECT
    COUNT(*) as total_adhoc_pendientes,
    SUM(CASE WHEN classname = '\\\\core\\\\task\\\\asynchronous_copy_task' THEN 1 ELSE 0 END) as async_copy_tasks,
    SUM(CASE WHEN timestarted IS NOT NULL THEN 1 ELSE 0 END) as tareas_corriendo,
    SUM(CASE WHEN faildelay > 0 THEN 1 ELSE 0 END) as tareas_fallidas
FROM ${DB_PREFIX}task_adhoc;
EOF

    echo -e "${YELLOW}Intentando consultar base de datos...${NC}"
    echo "Si pide contraseña, presiona Ctrl+C y ejecuta las queries manualmente"
    echo ""

    mysql -h "$DB_HOST" -u "$DB_USER" -p "$DB_NAME" < "$TMP_SQL" 2>/dev/null || \
        echo -e "${RED}No se pudo conectar automáticamente. Ejecuta manualmente:${NC}
        mysql -h $DB_HOST -u $DB_USER -p $DB_NAME < $TMP_SQL"

    rm -f "$TMP_SQL"
else
    echo -e "${RED}No se encontró config.php${NC}"
fi

################################################################################
# 3. PERMISOS Y ARCHIVOS
################################################################################
echo ""
echo "=============================================================================="
echo "3. PERMISOS Y ARCHIVOS TEMPORALES"
echo "=============================================================================="

# Detectar moodledata
if [ -f "$MOODLE_PATH/config.php" ]; then
    MOODLEDATA=$(grep '$CFG->dataroot' "$MOODLE_PATH/config.php" | cut -d"'" -f2 | head -1)

    if [ -n "$MOODLEDATA" ]; then
        echo -e "${GREEN}Moodledata: $MOODLEDATA${NC}"

        echo -e "\n${YELLOW}--- Permisos de moodledata ---${NC}"
        ls -ld "$MOODLEDATA" 2>/dev/null || echo "No se puede acceder a $MOODLEDATA"

        echo -e "\n${YELLOW}--- Permisos de temp/backup ---${NC}"
        ls -ld "$MOODLEDATA/temp/backup" 2>/dev/null || echo "Directorio temp/backup no existe"

        echo -e "\n${YELLOW}--- Archivos en temp/backup (últimos 10) ---${NC}"
        ls -lht "$MOODLEDATA/temp/backup" 2>/dev/null | head -11 || echo "No hay archivos o sin acceso"

        echo -e "\n${YELLOW}--- Espacio usado en moodledata ---${NC}"
        du -sh "$MOODLEDATA" 2>/dev/null || echo "No se puede calcular"
    fi
fi

################################################################################
# 4. PROCESOS ACTIVOS
################################################################################
echo ""
echo "=============================================================================="
echo "4. PROCESOS PHP/MOODLE ACTIVOS"
echo "=============================================================================="

echo -e "${YELLOW}--- Procesos adhoc_task corriendo ---${NC}"
ps aux | grep -E "adhoc_task|scheduled_task" | grep -v grep || echo "No hay procesos adhoc_task activos"

echo -e "\n${YELLOW}--- Procesos PHP del usuario ---${NC}"
ps aux | grep "$CURRENT_USER" | grep php | grep -v grep | head -10 || echo "No hay procesos PHP activos"

################################################################################
# 5. LOGS RECIENTES
################################################################################
echo ""
echo "=============================================================================="
echo "5. LOGS RECIENTES"
echo "=============================================================================="

echo -e "${YELLOW}--- Error log de Moodle (últimas 50 líneas) ---${NC}"
if [ -f "$MOODLE_PATH/error_log" ]; then
    tail -50 "$MOODLE_PATH/error_log" | grep -iE "backup|restore|fatal|error" | tail -20 || echo "Sin errores recientes"
else
    echo "No existe error_log en $MOODLE_PATH"
fi

echo -e "\n${YELLOW}--- LiteSpeed stderr log (últimas 30 líneas con moodle) ---${NC}"
if [ -f "/usr/local/lsws/logs/stderr.log" ]; then
    tail -100 /usr/local/lsws/logs/stderr.log 2>/dev/null | grep -i moodle | tail -30 || echo "Sin logs relacionados"
else
    echo "No se encuentra log de LiteSpeed o sin permisos"
fi

echo -e "\n${YELLOW}--- Messages del sistema (últimas 20 líneas con killed/oom) ---${NC}"
if [ -r "/var/log/messages" ]; then
    tail -100 /var/log/messages 2>/dev/null | grep -iE "killed|oom|out of memory" | tail -20 || echo "Sin eventos OOM"
else
    echo "No se puede leer /var/log/messages (requiere root)"
fi

################################################################################
# 6. TEST DE EJECUCIÓN MANUAL
################################################################################
echo ""
echo "=============================================================================="
echo "6. TEST DE EJECUCIÓN MANUAL"
echo "=============================================================================="

echo -e "${YELLOW}Preparando comando de prueba...${NC}"
echo ""

PHP_BIN="/opt/cpanel/ea-php81/root/usr/bin/php"
if [ ! -x "$PHP_BIN" ]; then
    PHP_BIN=$(which php)
fi

TEST_CMD="cd $MOODLE_PATH && $PHP_BIN -d max_execution_time=300 -d memory_limit=1G admin/cli/adhoc_task.php --classname=\\\\core\\\\task\\\\asynchronous_copy_task --execute"

echo -e "${GREEN}Comando sugerido para prueba manual:${NC}"
echo ""
echo "$TEST_CMD"
echo ""

read -p "¿Quieres ejecutar una prueba ahora? (s/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Ss]$ ]]; then
    echo -e "${YELLOW}Ejecutando prueba (timeout 5 minutos)...${NC}"
    timeout 300 bash -c "$TEST_CMD" 2>&1 | tee /tmp/moodle_async_test.log
    echo ""
    echo -e "${GREEN}Log guardado en: /tmp/moodle_async_test.log${NC}"
fi

################################################################################
# RESUMEN Y RECOMENDACIONES
################################################################################
echo ""
echo "=============================================================================="
echo "RESUMEN Y PRÓXIMOS PASOS"
echo "=============================================================================="
echo ""
echo "1. Revisa la salida anterior buscando:"
echo "   - Tareas con 'minutos_corriendo' > 30"
echo "   - Mensajes 'killed' o 'out of memory'"
echo "   - Errores de permisos en moodledata"
echo ""
echo "2. Si hay tareas bloqueadas, ejecuta en MySQL:"
echo "   UPDATE ${DB_PREFIX:-mdl_}task_adhoc SET timestarted=NULL, faildelay=0"
echo "   WHERE classname='\\\\core\\\\task\\\\asynchronous_copy_task' AND timestarted < UNIX_TIMESTAMP(NOW() - INTERVAL 1 HOUR);"
echo ""
echo "3. Si es problema de LVE (CloudLinux), contacta soporte para aumentar límites"
echo ""
echo "4. Revisa el archivo completo de diagnóstico:"
echo "   /tmp/moodle_async_test.log"
echo ""
echo "=============================================================================="
echo "Diagnóstico completado: $(date)"
echo "=============================================================================="
