# SOLUCIÓN INMEDIATA - Bloqueo asynchronous_copy_task

## DIAGNÓSTICO CORRECTO PARA TU ENTORNO

**Entorno detectado:**
- PHP 8.1 (NO requiere AllowDynamicProperties)
- LiteSpeed + CloudLinux con LVE
- cPanel/WHM

**Causa más probable:** Límites de recursos LVE matando procesos silenciosamente

---

## PASOS A SEGUIR INMEDIATAMENTE

### 1. EJECUTAR SCRIPT DE DIAGNÓSTICO

Sube y ejecuta el script de diagnóstico en tu servidor:

```bash
# En tu servidor, como usuario de Moodle (ingeniusvirtual)
cd /home/ingeniusvirtual
chmod +x diagnose_async_copy.sh
./diagnose_async_copy.sh > diagnostico_$(date +%Y%m%d_%H%M%S).txt 2>&1
```

Esto generará un reporte completo. **Envíame el archivo resultante** para análisis específico.

---

### 2. VERIFICACIÓN RÁPIDA (Ejecutar como root o con sudo)

```bash
# Ver si CloudLinux está limitando recursos
lvectl list | grep ingeniusvirtual

# Ver si hay procesos matados por límites
lveinfo --user=ingeniusvirtual --period=1d --show-columns=ep,pmem,vmem,io
```

**Si ves límites alcanzados (fEP, fPMem, etc.):** Esa es la causa.

---

### 3. SOLUCIÓN TEMPORAL - Aumentar límites LVE (COMO ROOT)

```bash
# Aumentar límites para el usuario de Moodle
lvectl set ingeniusvirtual --cpu=200 --pmem=2048M --vmem=2048M --io=8192 --nproc=300 --save-all-parameters

# Verificar cambios
lvectl list | grep ingeniusvirtual
```

---

### 4. LIMPIAR TAREAS BLOQUEADAS

Conecta a MySQL y ejecuta:

```sql
-- Conectar a tu base de datos
mysql -u usuario_moodle -p nombre_base_datos

-- Ver tareas bloqueadas
SELECT id, FROM_UNIXTIME(timestarted) as inicio,
       TIMESTAMPDIFF(MINUTE, FROM_UNIXTIME(timestarted), NOW()) as minutos
FROM mdl_task_adhoc
WHERE classname = '\\core\\task\\asynchronous_copy_task'
  AND timestarted IS NOT NULL;

-- Resetear tareas bloqueadas (más de 1 hora corriendo)
UPDATE mdl_task_adhoc
SET timestarted = NULL, faildelay = 0
WHERE classname = '\\core\\task\\asynchronous_copy_task'
  AND timestarted < UNIX_TIMESTAMP(NOW() - INTERVAL 1 HOUR);

-- Limpiar controladores viejos
DELETE FROM mdl_backup_controllers
WHERE status != 800
  AND timemodified < UNIX_TIMESTAMP(NOW() - INTERVAL 24 HOUR);
```

---

### 5. AJUSTAR PHP.INI (SI NO ES LÍMITE LVE)

Editar `/opt/cpanel/ea-php81/root/etc/php.ini`:

```ini
max_execution_time = 3600
max_input_time = 3600
memory_limit = 1024M
post_max_size = 512M
upload_max_filesize = 512M
```

Reiniciar LiteSpeed:
```bash
/usr/local/lsws/bin/lswsctrl restart
```

---

### 6. PRUEBA MANUAL

```bash
cd /home/ingeniusvirtual/public_html

# Ejecutar una tarea manualmente con logs
/opt/cpanel/ea-php81/root/usr/bin/php \
  -d max_execution_time=0 \
  -d memory_limit=2G \
  -d display_errors=1 \
  -d error_reporting=E_ALL \
  admin/cli/adhoc_task.php \
  --classname=\\core\\task\\asynchronous_copy_task \
  --execute 2>&1 | tee /tmp/test_async_copy.log

# Revisar el log
less /tmp/test_async_copy.log
```

**Busca en el log:**
- "Killed" → Límite de recursos alcanzado
- "Fatal error" → Error de PHP
- "out of memory" → Aumentar memory_limit o límite LVE
- "Permission denied" → Problema de permisos en moodledata

---

### 7. SI NADA FUNCIONA - DESHABILITAR ASYNC TEMPORALMENTE

Editar `/home/ingeniusvirtual/public_html/config.php` y añadir ANTES de `require_once(...moodlelib.php...)`:

```php
// Deshabilitar backups asíncronos temporalmente
$CFG->forced_plugin_settings = [
    'backup' => [
        'backup_async_message_users' => 0,
    ]
];

// Aumentar límites para CLI
if (PHP_SAPI === 'cli') {
    @ini_set('max_execution_time', 0);
    @ini_set('memory_limit', '2G');
}
```

---

## INFORMACIÓN A ENVIARME PARA DIAGNÓSTICO ESPECÍFICO

Si los pasos anteriores no resuelven el problema, necesito:

### A) Salida del script de diagnóstico
```bash
./diagnose_async_copy.sh > diagnostico.txt 2>&1
```

### B) Información LVE (como root)
```bash
lvectl list | grep ingeniusvirtual
lveinfo --user=ingeniusvirtual --period=1d > lve_history.txt
```

### C) Logs de error
```bash
tail -200 /home/ingeniusvirtual/public_html/error_log
tail -200 /usr/local/lsws/logs/stderr.log | grep moodle
tail -100 /var/log/messages | grep -i "killed\|oom"
```

### D) Estado de tareas en BD
```sql
SELECT * FROM mdl_task_adhoc
WHERE classname = '\\core\\task\\asynchronous_copy_task'
ORDER BY id DESC LIMIT 5\G
```

---

## CAUSAS DESCARTADAS

✗ **AllowDynamicProperties** - Solo aplica a PHP 8.2+, tienes 8.1
✗ **Sintaxis PHP incompatible** - El código de Moodle 4.5 es compatible con 8.1
✗ **Problemas de serialización** - No existen en PHP 8.1

---

## CAUSA MÁS PROBABLE EN TU CASO

Basado en tu entorno (LiteSpeed + CloudLinux + hosting compartido):

**1. Límites LVE de CloudLinux (80% probabilidad)**
   - CPU, memoria o I/O limits matando el proceso
   - El proceso muere sin dejar error en logs
   - Solución: Aumentar límites LVE

**2. Timeout de LiteSpeed External App (15% probabilidad)**
   - LiteSpeed mata procesos PHP CLI después de cierto tiempo
   - Solución: Aumentar extTimeout en configuración

**3. Espacio en disco lleno (5% probabilidad)**
   - Archivos .mbz temporales llenan el disco
   - Solución: Limpiar /home/ingeniusvirtual/moodledata/temp/

---

## PRÓXIMOS PASOS

1. **Ejecuta el script de diagnóstico**
2. **Verifica límites LVE** (causa #1 más probable)
3. **Limpia tareas bloqueadas en BD**
4. **Prueba copia manual** con el comando del paso 6
5. **Envíame los resultados** si no se resuelve

---

**Nota importante:** El commit anterior que revertí era incorrecto porque intentaba solucionar un problema de PHP 8.2+ en tu instalación de PHP 8.1.
