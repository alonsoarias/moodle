# DIAGNÓSTICO: Bloqueo de asynchronous_copy_task en PHP 8.1 + LiteSpeed + CloudLinux

## ENTORNO DETECTADO
- PHP 8.1 (ea-php81 cPanel)
- LiteSpeed API (lsapi V8.0.1)
- CloudLinux con LVE (Linux Virtual Environment)
- Servidor: perceus.orioncloud.com.co

## CAUSAS PROBABLES EN ESTE ENTORNO

### 1. LÍMITES LVE DE CLOUDLINUX (MÁS PROBABLE)
CloudLinux impone límites estrictos que matan procesos sin errores visibles:

**Verificar límites actuales:**
```bash
# Ver límites LVE del usuario
lvectl list
cloudlinux-limits --json

# Ver uso actual
lvetop
```

**Síntomas:**
- Proceso se detiene sin error en logs
- `extract_to_pathname()` (línea 113 de asynchronous_copy_task) se cuelga
- Backup/restore grande excede CPU/memoria/IO permitidos

**Solución:**
```bash
# Aumentar límites temporalmente para usuario de Moodle
lvectl set <usuario> --cpu=100 --pmem=2G --vmem=2G --io=4096 --nproc=200
```

### 2. LITESPEED EXTERNAL APP TIMEOUT
LiteSpeed mata procesos CLI después de cierto tiempo.

**Verificar configuración:**
```bash
# Buscar en configuración de LiteSpeed
grep -r "extMaxIdleTime\|extTimeout" /usr/local/lsws/conf/
```

**Solución:**
Editar `/usr/local/lsws/conf/httpd_config.conf`:
```xml
<extProcessor>
    <extMaxIdleTime>3600</extMaxIdleTime>
    <extTimeout>3600</extTimeout>
</extProcessor>
```

### 3. PHP MAX_EXECUTION_TIME INSUFICIENTE

**Verificar configuración:**
```bash
# Ver configuración PHP CLI
/opt/cpanel/ea-php81/root/usr/bin/php -i | grep max_execution_time

# Ver configuración cron de Moodle
grep max_execution_time /home/ingeniusvirtual/public_html/config.php
```

**Ubicación del php.ini CLI:**
```
/opt/cpanel/ea-php81/root/etc/php.ini
```

**Solución:**
Editar `/opt/cpanel/ea-php81/root/etc/php.ini`:
```ini
max_execution_time = 3600
max_input_time = 3600
memory_limit = 512M
```

### 4. PERMISOS EN MOODLEDATA
LiteSpeed/cron pueden correr con usuarios diferentes.

**Verificar:**
```bash
# Ver propietario de moodledata
ls -la /home/ingeniusvirtual/moodledata/temp/backup/

# Ver usuario que corre cron
ps aux | grep cron | grep moodle
```

**Solución:**
```bash
# Ajustar permisos
chown -R ingeniusvirtual:ingeniusvirtual /home/ingeniusvirtual/moodledata
chmod -R 770 /home/ingeniusvirtual/moodledata/temp
```

### 5. CLAMAV/ANTIVIRUS BLOQUEANDO ARCHIVOS
En hosting compartido, ClamAV puede escanear archivos .mbz y bloquearlos.

**Verificar:**
```bash
# Ver logs de ClamAV
tail -100 /var/log/clamav/clamd.log | grep -i backup

# Excluir directorio de escaneo
echo "/home/ingeniusvirtual/moodledata/temp/" >> /etc/clamav/clamd.exclude
```

### 6. DATABASE CONNECTION TIMEOUT
MariaDB puede cerrar conexiones largas.

**Verificar:**
```bash
mysql -e "SHOW VARIABLES LIKE '%timeout%';"
```

**Solución en config.php de Moodle:**
```php
$CFG->dboptions = array(
    'dbpersist' => false,
    'dbsocket' => false,
    'connecttimeout' => 30,
);
```

## COMANDOS DE DIAGNÓSTICO PRIORITARIOS

### 1. Ejecutar tarea manualmente con debug:
```bash
cd /home/ingeniusvirtual/public_html
/opt/cpanel/ea-php81/root/usr/bin/php -d max_execution_time=3600 -d memory_limit=1G \
    admin/cli/adhoc_task.php \
    --classname=\\core\\task\\asynchronous_copy_task \
    --execute 2>&1 | tee /tmp/async_copy_debug.log
```

### 2. Verificar tareas bloqueadas en BD:
```sql
-- Conectar a MySQL
mysql -u <usuario_db> -p <nombre_db>

-- Ver tareas adhoc pendientes
SELECT id, classname, faildelay,
       FROM_UNIXTIME(nextruntime) as proxima_ejecucion,
       FROM_UNIXTIME(timestarted) as inicio,
       TIMESTAMPDIFF(MINUTE, FROM_UNIXTIME(timestarted), NOW()) as minutos_corriendo
FROM mdl_task_adhoc
WHERE classname = '\\core\\task\\asynchronous_copy_task'
ORDER BY id DESC LIMIT 10;

-- Ver controladores backup/restore
SELECT id, backupid, operation, status, execution,
       FROM_UNIXTIME(timecreated) as creado,
       FROM_UNIXTIME(timemodified) as modificado,
       TIMESTAMPDIFF(MINUTE, FROM_UNIXTIME(timecreated), NOW()) as edad_minutos
FROM mdl_backup_controllers
WHERE status != 800
ORDER BY timecreated DESC LIMIT 10;
```

### 3. Verificar logs relevantes:
```bash
# Logs de LiteSpeed
tail -200 /usr/local/lsws/logs/stderr.log | grep -i moodle

# Logs de error PHP
tail -200 /home/ingeniusvirtual/public_html/error_log

# Logs de CloudLinux LVE
tail -100 /var/log/lve-stats.log

# Mensajes del sistema
tail -100 /var/log/messages | grep -i "killed\|oom"
```

### 4. Verificar límites LVE:
```bash
# Límites actuales del usuario
lvectl list | grep ingeniusvirtual

# Historial de límites alcanzados
lveinfo --user=ingeniusvirtual --period=1d

# Monitoreo en tiempo real
lvetop
```

## SOLUCIÓN RECOMENDADA PASO A PASO

### PASO 1: Aumentar límites temporalmente
```bash
# Como root
lvectl set ingeniusvirtual --cpu=200 --pmem=2G --vmem=2G --io=8192 --nproc=300 --save-all-parameters
```

### PASO 2: Ajustar PHP CLI
```bash
# Editar /opt/cpanel/ea-php81/root/etc/php.ini
sed -i 's/max_execution_time = .*/max_execution_time = 3600/' /opt/cpanel/ea-php81/root/etc/php.ini
sed -i 's/memory_limit = .*/memory_limit = 1G/' /opt/cpanel/ea-php81/root/etc/php.ini
```

### PASO 3: Limpiar tareas bloqueadas
```sql
-- Marcar tareas antiguas como fallidas
UPDATE mdl_task_adhoc
SET faildelay = 0, timestarted = NULL
WHERE classname = '\\core\\task\\asynchronous_copy_task'
  AND timestarted IS NOT NULL
  AND timestarted < UNIX_TIMESTAMP(NOW() - INTERVAL 2 HOUR);

-- Limpiar controladores viejos
DELETE FROM mdl_backup_controllers
WHERE status != 800
  AND timemodified < UNIX_TIMESTAMP(NOW() - INTERVAL 24 HOUR);
```

### PASO 4: Probar copia manual
```bash
cd /home/ingeniusvirtual/public_html
/opt/cpanel/ea-php81/root/usr/bin/php \
    -d max_execution_time=0 \
    -d memory_limit=2G \
    -d display_errors=1 \
    -d error_reporting=E_ALL \
    admin/cli/adhoc_task.php \
    --classname=\\core\\task\\asynchronous_copy_task \
    --execute 2>&1 | tee /tmp/test_copy.log
```

### PASO 5: Si sigue fallando, deshabilitar async y usar copia síncrona
En `config.php`:
```php
// Deshabilitar backups asíncronos temporalmente
$CFG->forced_plugin_settings = [
    'backup' => [
        'backup_async_message_users' => 0,
        'backup_auto_active' => 0,
    ]
];
```

## MONITOREO POST-FIX

```bash
# Script para monitorear tareas async
watch -n 5 "echo 'SELECT COUNT(*) as tareas_pendientes FROM mdl_task_adhoc WHERE classname=\"\\\\core\\\\task\\\\asynchronous_copy_task\"' | mysql -u usuario -p base_datos"

# Ver uso de recursos
watch -n 2 "ps aux | grep 'adhoc_task\|php.*backup' | grep -v grep"
```

## CAUSAS DESCARTADAS
- ✗ AllowDynamicProperties - Solo aplica a PHP 8.2+
- ✗ Problemas de serialización - PHP 8.1 no tiene ese issue
- ✗ Sintaxis incompatible - El código es compatible con 8.1
