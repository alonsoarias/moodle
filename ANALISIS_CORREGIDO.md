# ANÁLISIS CORREGIDO - Límites LVE NO son el problema

## ❌ ERROR EN ANÁLISIS ANTERIOR

**Interpreté mal los límites de I/O:**
- Pensé: 1.2 MB/s ← INCORRECTO
- Real: **1.21 GB/s** ← CORRECTO (1,210 MB/s)

## ✅ LÍMITES REALES DE ingeniusvirtual

Los límites son **EXCELENTES** y NO son la causa del problema:

```
CPU:              650% (6.5 cores)          ✅ Sobrado
RAM Física:       24 GB                     ✅ Sobrado
RAM Virtual:      48 GB                     ✅ Sobrado
I/O:              1.21 GB/s (1,210 MB/s)   ✅ MUY ALTO
IOPS:             494,933                   ✅ Sobrado
Entry Processes:  180                       ✅ Suficiente
Procesos Totales: 600                       ✅ Sobrado
```

---

## 🔍 CAUSAS REALES PROBABLES (ORDEN DE PROBABILIDAD)

### 1. TIMEOUT DE PHP CLI (60-70% probabilidad)

**Síntoma:** Proceso se detiene después de cierto tiempo sin error visible.

**Verificar:**
```bash
# Como root o ingeniusvirtual
/opt/cpanel/ea-php81/root/usr/bin/php -i | grep -E "max_execution_time|max_input_time"
```

**Solución:**
```bash
# Editar php.ini CLI
nano /opt/cpanel/ea-php81/root/etc/php.ini

# Cambiar:
max_execution_time = 7200
max_input_time = 7200
memory_limit = 2048M
```

### 2. LITESPEED EXTERNAL APP TIMEOUT (20% probabilidad)

**Síntoma:** LiteSpeed mata procesos PHP CLI después de tiempo límite.

**Verificar:**
```bash
grep -r "extMaxIdleTime\|extTimeout\|lsphp" /usr/local/lsws/conf/ 2>/dev/null
```

**Solución:**
Editar `/usr/local/lsws/conf/httpd_config.conf` y buscar sección `<extProcessor>`:
```xml
<extProcessor>
  <extMaxIdleTime>7200</extMaxIdleTime>
  <extTimeout>7200</extTimeout>
</extProcessor>
```

Reiniciar: `/usr/local/lsws/bin/lswsctrl restart`

### 3. LOCKS DE BASE DE DATOS (10% probabilidad)

**Síntoma:** Proceso espera indefinidamente por lock de tabla/fila.

**Verificar:**
```sql
-- Como root MySQL
mysql -e "SHOW FULL PROCESSLIST;"
mysql -e "SHOW ENGINE INNODB STATUS\G" | grep -A 20 "TRANSACTIONS"
```

**Solución:**
```sql
-- Ver locks activos
SELECT * FROM information_schema.innodb_trx;
SELECT * FROM information_schema.innodb_locks;

-- Matar proceso bloqueante (CUIDADO)
-- KILL <process_id>;
```

### 4. PERMISOS O ESPACIO EN DISCO (5% probabilidad)

**Verificar:**
```bash
# Espacio en disco
df -h /home/ingeniusvirtual

# Permisos de moodledata
ls -la /home/ingeniusvirtual/moodledata/temp/backup

# Inodos disponibles
df -i /home/ingeniusvirtual
```

### 5. ERROR SILENCIOSO EN CÓDIGO MOODLE (5% probabilidad)

**Verificar logs:**
```bash
tail -200 /home/ingeniusvirtual/public_html/error_log
tail -200 /usr/local/lsws/logs/stderr.log | grep moodle
tail -100 /var/log/messages | grep -i "php\|moodle"
```

---

## 🚀 DIAGNÓSTICO CORRECTO PASO A PASO

### PASO 1: Verificar configuración PHP actual

```bash
su - ingeniusvirtual
cd /home/ingeniusvirtual/public_html

/opt/cpanel/ea-php81/root/usr/bin/php -i | grep -E "max_execution_time|max_input_time|memory_limit"
```

**Valores esperados:**
- max_execution_time debe ser > 3600 (1 hora) o 0 (ilimitado)
- memory_limit debe ser > 1024M

### PASO 2: Ver tareas bloqueadas en BD

```bash
# Obtener credenciales de config.php
grep -E "dbhost|dbname|dbuser|dbpass|prefix" /home/ingeniusvirtual/public_html/config.php
```

```sql
-- Conectar y consultar
mysql -h <host> -u <user> -p<pass> <dbname>

-- Ver tareas en ejecución
SELECT id, classname,
       FROM_UNIXTIME(nextruntime) as proxima,
       FROM_UNIXTIME(timestarted) as inicio,
       TIMESTAMPDIFF(MINUTE, FROM_UNIXTIME(timestarted), NOW()) as minutos_corriendo,
       faildelay
FROM mdl_task_adhoc
WHERE classname = '\\core\\task\\asynchronous_copy_task'
ORDER BY id DESC LIMIT 10;

-- Ver controladores
SELECT id, operation, status, execution,
       FROM_UNIXTIME(timecreated) as creado,
       TIMESTAMPDIFF(MINUTE, FROM_UNIXTIME(timecreated), NOW()) as edad_minutos
FROM mdl_backup_controllers
WHERE status != 800
ORDER BY timecreated DESC LIMIT 10;
```

### PASO 3: Prueba manual con logs completos

```bash
su - ingeniusvirtual
cd /home/ingeniusvirtual/public_html

# Ejecutar con timeout de 30 minutos y logs verbosos
timeout 1800 /opt/cpanel/ea-php81/root/usr/bin/php \
  -d max_execution_time=0 \
  -d memory_limit=2G \
  -d display_errors=1 \
  -d error_reporting=E_ALL \
  -d log_errors=1 \
  -d error_log=/tmp/php_async_copy_debug.log \
  admin/cli/adhoc_task.php \
  --classname=\\core\\task\\asynchronous_copy_task \
  --execute --showdebugging 2>&1 | tee /tmp/moodle_async_copy_test.log

# Ver resultado
echo "Exit code: $?"
tail -50 /tmp/php_async_copy_debug.log
tail -50 /tmp/moodle_async_copy_test.log
```

**Interpretar resultado:**
- Exit code 0 = ✅ Éxito
- Exit code 124 = Timeout de 30 minutos alcanzado (curso muy grande)
- Exit code 137 = Proceso matado (por OOM killer o señal externa)
- Exit code 255 = Error de PHP
- Se cuelga sin salida = Posible lock de BD o timeout

### PASO 4: Monitorear durante ejecución

En otra terminal como root:

```bash
# Ver procesos PHP activos
watch -n 2 'ps aux | grep -E "adhoc_task|php.*backup" | grep -v grep'

# Ver uso de recursos en tiempo real
watch -n 2 'ps aux | grep ingeniusvirtual | grep php | awk "{sum+=\$3} END {print sum}"; ps aux | grep ingeniusvirtual | grep php | awk "{sum+=\$4} END {print sum}"'

# Ver locks de MySQL
watch -n 5 'mysql -e "SHOW FULL PROCESSLIST" | grep -E "Locked|backup|restore"'
```

---

## 🔧 SOLUCIONES PRIORIZADAS

### Solución #1: Ajustar PHP CLI (HACER PRIMERO)

```bash
# Como root
nano /opt/cpanel/ea-php81/root/etc/php.ini

# Cambiar estas líneas:
max_execution_time = 7200
max_input_time = 7200
memory_limit = 2048M
post_max_size = 512M
upload_max_filesize = 512M

# NO necesita reiniciar LiteSpeed (aplica inmediatamente para CLI)
```

### Solución #2: Optimizar config.php de Moodle

```bash
# Como ingeniusvirtual
nano /home/ingeniusvirtual/public_html/config.php
```

Añadir ANTES de `require_once(__DIR__ . '/lib/setup.php');`:

```php
// Optimización para CLI
if (PHP_SAPI === 'cli') {
    @ini_set('max_execution_time', 0);
    @ini_set('memory_limit', '2G');
    @ini_set('display_errors', '1');
}

// Optimización de base de datos
$CFG->dboptions = array(
    'dbpersist' => false,
    'dbsocket' => false,
    'connecttimeout' => 30,
    'dbcollation' => 'utf8mb4_unicode_ci',
);

// Aumentar timeouts de backup/restore
$CFG->task_adhoc_max_runtime = 7200; // 2 horas
```

### Solución #3: Limpiar tareas bloqueadas

```sql
-- Como usuario de BD de Moodle
USE nombre_base_datos;

-- Resetear tareas bloqueadas (más de 2 horas)
UPDATE mdl_task_adhoc
SET timestarted = NULL, faildelay = 0
WHERE classname = '\\core\\task\\asynchronous_copy_task'
  AND timestarted < UNIX_TIMESTAMP(NOW() - INTERVAL 2 HOUR);

-- Eliminar controladores viejos
DELETE FROM mdl_backup_controllers
WHERE status != 800
  AND timemodified < UNIX_TIMESTAMP(NOW() - INTERVAL 48 HOUR);

-- Verificar limpieza
SELECT COUNT(*) as tareas_pendientes
FROM mdl_task_adhoc
WHERE classname = '\\core\\task\\asynchronous_copy_task';
```

### Solución #4: Si es timeout de LiteSpeed

```bash
# Como root
nano /usr/local/lsws/conf/httpd_config.conf

# Buscar <extProcessor> y ajustar:
# Si no existe, agregarlo en la sección adecuada
<extProcessor>
  <type>lsapi</type>
  <address>uds://tmp/lshttpd/lsphp.sock</address>
  <maxConns>35</maxConns>
  <env>PHP_LSAPI_CHILDREN=35</env>
  <initTimeout>600</initTimeout>
  <retryTimeout>0</retryTimeout>
  <persistConn>1</persistConn>
  <pcKeepAliveTimeout>1</pcKeepAliveTimeout>
  <respBuffer>0</respBuffer>
  <autoStart>1</autoStart>
  <path>/opt/cpanel/ea-php81/root/usr/bin/lsphp</path>
  <backlog>100</backlog>
  <instances>1</instances>
  <priority>0</priority>
  <memSoftLimit>2048M</memSoftLimit>
  <memHardLimit>2048M</memHardLimit>
  <procSoftLimit>400</procSoftLimit>
  <procHardLimit>500</procHardLimit>
  <extMaxIdleTime>7200</extMaxIdleTime>
  <extTimeout>7200</extTimeout>
</extProcessor>

# Reiniciar LiteSpeed
/usr/local/lsws/bin/lswsctrl restart
```

---

## 📊 QUÉ INFORMACIÓN NECESITO DE TI

Para darte la solución exacta, ejecuta estos comandos y envíame la salida:

### 1. Configuración PHP actual
```bash
su - ingeniusvirtual
/opt/cpanel/ea-php81/root/usr/bin/php -i | grep -E "max_execution_time|max_input_time|memory_limit" | head -10
```

### 2. Estado de tareas en BD
```bash
# Reemplaza con tus credenciales
mysql -h localhost -u usuario_moodle -p nombre_bd -e "
SELECT id, FROM_UNIXTIME(timestarted) as inicio,
       TIMESTAMPDIFF(MINUTE, FROM_UNIXTIME(timestarted), NOW()) as minutos,
       faildelay
FROM mdl_task_adhoc
WHERE classname='\\\\core\\\\task\\\\asynchronous_copy_task'
ORDER BY id DESC LIMIT 5;"
```

### 3. Prueba manual
```bash
su - ingeniusvirtual
cd /home/ingeniusvirtual/public_html
timeout 300 /opt/cpanel/ea-php81/root/usr/bin/php \
  -d display_errors=1 -d error_reporting=E_ALL \
  admin/cli/adhoc_task.php \
  --classname=\\core\\task\\asynchronous_copy_task \
  --execute --showdebugging 2>&1 | tee /tmp/test_output.log

echo "Exit code: $?"
cat /tmp/test_output.log
```

---

## 🎯 RESUMEN

**Límites LVE:** ✅ NO son el problema
**Causa probable:** Timeout de PHP o LiteSpeed
**Siguiente paso:** Ajustar configuración PHP y probar manualmente

Los scripts que creé antes siguen siendo útiles para limpiar tareas, pero necesito corregir el enfoque ya que los límites LVE son más que suficientes.
