# INSTRUCCIONES INMEDIATAS - ingeniusvirtual

## ✅ LÍMITES LVE DETECTADOS

Tu usuario `ingeniusvirtual` tiene estos límites en CloudLinux:

```
CPU:              650% (6.5 cores) ✓ Bueno
RAM Física:       24 GB            ✓ Bueno
RAM Virtual:      48 GB            ✓ Bueno
I/O:              ~1.2 MB/s        ⚠️  PUEDE SER INSUFICIENTE
IOPS:             ~495K            ✓ Bueno
Entry Processes:  180              ✓ Bueno
Procesos Totales: 600              ✓ Bueno
```

**SOSPECHA PRINCIPAL:** El límite de **I/O (1.2 MB/s)** puede ser insuficiente para operaciones de backup/restore que descomprimen archivos .mbz grandes.

---

## 🚀 PASOS A EJECUTAR AHORA (COMO ROOT)

### 1. Descargar scripts del repositorio

```bash
cd /tmp
wget https://raw.githubusercontent.com/alonsoarias/moodle/claude/moodle-async-copy-task-debug-011CUjCHTQpzft9PbHZokZue/check_lve_faults.sh
wget https://raw.githubusercontent.com/alonsoarias/moodle/claude/moodle-async-copy-task-debug-011CUjCHTQpzft9PbHZokZue/fix_async_copy_litespeed.sh

chmod +x check_lve_faults.sh fix_async_copy_litespeed.sh
```

O clona el repositorio:
```bash
cd /tmp
git clone https://github.com/alonsoarias/moodle.git
cd moodle
git checkout claude/moodle-async-copy-task-debug-011CUjCHTQpzft9PbHZokZue
cp check_lve_faults.sh fix_async_copy_litespeed.sh /root/
```

### 2. Verificar si hay límites alcanzados

```bash
cd /root
./check_lve_faults.sh
```

**Busca columnas con "f" (faults):**
- `fIO` - Si este número es > 0, el I/O está siendo limitado ← **CAUSA PRINCIPAL**
- `fIOPS` - Si este número es > 0, los IOPS están siendo limitados
- `fPMem` - Si este número es > 0, se agota la RAM
- `fEP` - Si este número es > 0, se agotan los entry processes

### 3. Ejecutar script de corrección automática

```bash
./fix_async_copy_litespeed.sh
```

Este script hará:
1. ✓ Verificar faults LVE y ofrecer aumentar límites I/O si es necesario
2. ✓ Ajustar PHP CLI (max_execution_time=3600s, memory_limit=2048M)
3. ✓ Limpiar tareas adhoc bloqueadas en BD
4. ✓ Corregir permisos de moodledata
5. ✓ Eliminar archivos temporales antiguos
6. ✓ Optimizar config.php de Moodle para CLI
7. ✓ Ejecutar prueba manual

---

## 🔍 SI EL SCRIPT DETECTA FAULTS DE I/O

El script te preguntará si quieres aumentar límites. **Responde "s" (sí).**

O puedes hacerlo manualmente:

```bash
# Duplicar límites de I/O temporalmente
lvectl set ingeniusvirtual --io=2532010 --iops=989866 --save-all-parameters

# Verificar cambios
lvectl list | grep ingeniusvirtual
```

---

## 📊 MONITOREO POST-FIX

### Ver si las tareas están corriendo:

```bash
# Ver tareas en tiempo real
watch -n 5 "mysql -u root -p nombre_bd -e 'SELECT id, FROM_UNIXTIME(timestarted) as inicio, TIMESTAMPDIFF(MINUTE, FROM_UNIXTIME(timestarted), NOW()) as minutos FROM mdl_task_adhoc WHERE classname=\"\\\\core\\\\task\\\\asynchronous_copy_task\" AND timestarted IS NOT NULL'"
```

### Ver uso de recursos en tiempo real:

```bash
# Ver uso LVE en tiempo real (pulsa 'q' para salir)
lvetop
```

### Ver si se están alcanzando límites:

```bash
# Ver faults en la última hora
lveinfo --user=ingeniusvirtual --period=1h --show-columns=io,iops,pmem,ep
```

---

## 🧪 PRUEBA MANUAL (SI EL SCRIPT NO LA EJECUTÓ)

Como usuario `ingeniusvirtual`:

```bash
su - ingeniusvirtual
cd /home/ingeniusvirtual/public_html

/opt/cpanel/ea-php81/root/usr/bin/php \
  -d max_execution_time=0 \
  -d memory_limit=2G \
  -d display_errors=1 \
  -d error_reporting=E_ALL \
  admin/cli/adhoc_task.php \
  --classname=\\core\\task\\asynchronous_copy_task \
  --execute --showdebugging 2>&1 | tee /tmp/async_copy_test_$(date +%Y%m%d_%H%M%S).log
```

**Observa la salida:**
- Si dice "Killed" → Límite LVE alcanzado
- Si dice "out of memory" → Aumentar memory_limit o límite pmem
- Si dice "Permission denied" → Problema de permisos en moodledata
- Si se cuelga sin salida → Verificar `lvetop` en otra terminal

---

## ⚠️ SI SIGUE SIN FUNCIONAR

### Opción 1: Aumentar más los límites I/O

```bash
# Triplicar límites de I/O
lvectl set ingeniusvirtual --io=3798015 --iops=1484799 --save-all-parameters
```

### Opción 2: Deshabilitar async temporalmente

Edita `/home/ingeniusvirtual/public_html/config.php` y añade ANTES de `require_once(...lib/setup.php...)`:

```php
// Deshabilitar copias asíncronas temporalmente
$CFG->forced_plugin_settings = [
    'backup' => [
        'backup_async_message_users' => 0,
    ]
];
```

Esto hará que las copias se ejecuten síncronamente (en el navegador del usuario).

### Opción 3: Ejecutar copias manualmente con cron

Crear script en `/root/moodle_adhoc_runner.sh`:

```bash
#!/bin/bash
cd /home/ingeniusvirtual/public_html
/opt/cpanel/ea-php81/root/usr/bin/php \
  -d max_execution_time=0 \
  -d memory_limit=2G \
  admin/cli/adhoc_task.php \
  --classname=\\core\\task\\asynchronous_copy_task \
  --execute >> /var/log/moodle_async_copy.log 2>&1
```

Y agregarlo a cron (cada 5 minutos):

```bash
chmod +x /root/moodle_adhoc_runner.sh
crontab -e
# Añadir:
*/5 * * * * /root/moodle_adhoc_runner.sh
```

---

## 📋 INFORMACIÓN A RECOPILAR SI PERSISTE EL PROBLEMA

Ejecuta y envíame la salida:

```bash
# 1. Faults históricos
lveinfo --user=ingeniusvirtual --period=1d --show-columns=io,iops,pmem,ep > /tmp/lve_history.txt

# 2. Estado actual de tareas
mysql -u root -p nombre_bd -e "SELECT * FROM mdl_task_adhoc WHERE classname='\\\\core\\\\task\\\\asynchronous_copy_task' ORDER BY id DESC LIMIT 5\G" > /tmp/tasks_status.txt

# 3. Último intento de ejecución manual
su - ingeniusvirtual -c "cd /home/ingeniusvirtual/public_html && /opt/cpanel/ea-php81/root/usr/bin/php -d display_errors=1 -d error_reporting=E_ALL admin/cli/adhoc_task.php --classname=\\\\core\\\\task\\\\asynchronous_copy_task --execute --showdebugging" > /tmp/manual_test.log 2>&1

# 4. Logs de error
tail -200 /home/ingeniusvirtual/public_html/error_log > /tmp/moodle_errors.txt
tail -200 /usr/local/lsws/logs/stderr.log | grep moodle > /tmp/litespeed_errors.txt

# Comprimir todo
tar -czf /tmp/moodle_debug_$(date +%Y%m%d).tar.gz /tmp/lve_history.txt /tmp/tasks_status.txt /tmp/manual_test.log /tmp/moodle_errors.txt /tmp/litespeed_errors.txt
```

Y envía `/tmp/moodle_debug_*.tar.gz`

---

## ✅ CHECKLIST DE VERIFICACIÓN

Después de ejecutar `fix_async_copy_litespeed.sh`, verifica:

- [ ] No hay faults de I/O en última hora: `lveinfo --user=ingeniusvirtual --period=1h`
- [ ] PHP CLI tiene max_execution_time=3600: `php -i | grep max_execution_time`
- [ ] No hay tareas bloqueadas (>1h): Verificar en BD
- [ ] Permisos correctos en moodledata/temp/backup: `ls -la /ruta/moodledata/temp/backup`
- [ ] Prueba manual funciona sin "Killed"
- [ ] Una copia real de curso se completa exitosamente

---

## 📞 SOPORTE ADICIONAL

Si después de todos estos pasos el problema persiste:

1. Contacta a tu proveedor CloudLinux/hosting para:
   - Revisar logs del kernel: `dmesg | grep -i killed`
   - Verificar si hay otros límites ocultos
   - Considerar un upgrade de plan con más I/O

2. En Moodle, como alternativa temporal:
   - Usar la copia síncrona en lugar de asíncrona
   - Dividir cursos grandes en secciones más pequeñas
   - Hacer backups durante horas de bajo tráfico

---

**IMPORTANTE:** Los límites que tienes (24GB RAM, 650% CPU) son muy buenos. El **cuello de botella probable es el I/O de disco (1.2 MB/s)** durante la descompresión de archivos .mbz grandes.
