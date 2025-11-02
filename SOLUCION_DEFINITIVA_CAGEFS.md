# SOLUCIÓN DEFINITIVA - CageFS bloqueando async copy en Moodle

## 🎯 PROBLEMA CONFIRMADO

**CageFS de CloudLinux** está bloqueando las tareas `asynchronous_copy_task`.

Al desactivar CageFS, las copias funcionan → **CageFS es la causa raíz**.

---

## ⚠️ NO DESACTIVAR CAGEFS PERMANENTEMENTE

CageFS es una característica de **seguridad importante** que aísla usuarios en hosting compartido.

**SOLUCIÓN:** Configurar CageFS correctamente para Moodle, no desactivarlo.

---

## 💊 SOLUCIÓN 1: Configurar CageFS para Moodle (RECOMENDADO)

### Paso 1: Actualizar CageFS para incluir binarios necesarios

```bash
# Como root
cagefsctl --update

# Esto actualiza la lista de binarios permitidos en CageFS
```

### Paso 2: Agregar binarios específicos que Moodle necesita

Moodle usa estos binarios durante backup/restore:

```bash
# Como root
# Agregar binarios al skeleton de CageFS
cat >> /etc/cagefs/conf.d/moodle.cfg << 'EOF'
# Binarios necesarios para Moodle backup/restore
paths=/usr/bin/zip,/bin/zip
paths=/usr/bin/unzip,/bin/unzip
paths=/usr/bin/tar,/bin/tar
paths=/usr/bin/gzip,/bin/gzip
paths=/usr/bin/gunzip,/bin/gunzip
paths=/usr/bin/bzip2,/bin/bzip2
paths=/usr/bin/bunzip2,/bin/bunzip2

# PHP y extensiones
paths=/opt/cpanel/ea-php81/root/usr/bin/php
paths=/opt/cpanel/ea-php81/root/usr/lib64/php/modules

# Librerías necesarias
paths=/usr/lib64/libzip.so.5
paths=/usr/lib64/libz.so.1
EOF

# Aplicar cambios
cagefsctl --force-update
cagefsctl --remount-all
```

### Paso 3: Verificar que el usuario tiene los binarios disponibles

```bash
# Verificar como usuario ingeniusvirtual (desde CageFS)
su - ingeniusvirtual -s /bin/bash -c "which zip"
su - ingeniusvirtual -s /bin/bash -c "which tar"
su - ingeniusvirtual -s /bin/bash -c "which php"

# Deberían mostrar los paths, no "command not found"
```

### Paso 4: Verificar acceso a directorios temporales

```bash
# Como root
# Verificar que moodledata/temp está accesible desde CageFS
ls -la /home/ingeniusvirtual/moodledata/temp/

# Verificar permisos
chown -R ingeniusvirtual:ingeniusvirtual /home/ingeniusvirtual/moodledata
chmod -R 770 /home/ingeniusvirtual/moodledata/temp
```

### Paso 5: Reactivar CageFS para el usuario

```bash
# Como root
cagefsctl --enable ingeniusvirtual
cagefsctl --remount ingeniusvirtual
```

### Paso 6: PROBAR

```bash
# Como ingeniusvirtual
cd /home/ingeniusvirtual/public_html

/opt/cpanel/ea-php81/root/usr/bin/php \
  admin/cli/adhoc_task.php \
  --classname=\\core\\task\\asynchronous_copy_task \
  --execute --showdebugging 2>&1 | tee /tmp/test_con_cagefs.log
```

**Si funciona:** ✅ Problema resuelto, CageFS configurado correctamente

**Si falla:** Pasar a Solución 2

---

## 💊 SOLUCIÓN 2: Deshabilitar CageFS solo para ingeniusvirtual

Si la Solución 1 no funciona, deshabilita CageFS SOLO para este usuario:

```bash
# Como root
cagefsctl --disable ingeniusvirtual

# Verificar
cagefsctl --list-disabled
```

**Ventajas:**
- Moodle funciona sin restricciones
- Otros usuarios siguen protegidos por CageFS

**Desventajas:**
- Usuario ingeniusvirtual tiene acceso completo al filesystem
- Menor aislamiento de seguridad

---

## 💊 SOLUCIÓN 3: Configuración alternativa de PHP en CageFS

Asegurar que PHP CLI tiene acceso completo:

```bash
# Como root
# Crear configuración específica para PHP CLI
cat > /etc/cagefs/conf.d/php-cli.cfg << 'EOF'
# PHP CLI configuration for CageFS
paths=/opt/cpanel/ea-php81
paths=/opt/cpanel/ea-php81/root/usr/bin
paths=/opt/cpanel/ea-php81/root/usr/lib64
paths=/opt/cpanel/ea-php81/root/etc

# Temp directories
paths=/tmp
paths=/var/tmp
EOF

# Actualizar
cagefsctl --force-update
cagefsctl --remount-all
```

---

## 💊 SOLUCIÓN 4: Usar modo "Transparent" para usuarios específicos

CloudLinux permite modo "transparent" que da más acceso:

```bash
# Como root
# Agregar usuario a lista transparente
echo "ingeniusvirtual" >> /etc/cagefs/users.disabled

# O deshabilitar para todo el paquete
cagefsctl --disable-pkg 200UD40GB
```

---

## 🔍 DIAGNÓSTICO AVANZADO

Si ninguna solución funciona, diagnostica exactamente qué está bloqueando CageFS:

### 1. Ver logs de CageFS

```bash
# Como root
tail -100 /var/log/messages | grep -i cagefs
tail -100 /var/log/secure | grep -i cagefs
dmesg | grep -i cagefs
```

### 2. Ejecutar con strace para ver qué falla

```bash
# Como root
# Ejecutar tarea con strace (dentro de CageFS)
su - ingeniusvirtual -c "cd /home/ingeniusvirtual/public_html && strace -o /tmp/strace.log -ff /opt/cpanel/ea-php81/root/usr/bin/php admin/cli/adhoc_task.php --classname=\\\\core\\\\task\\\\asynchronous_copy_task --execute 2>&1"

# Buscar errores en el trace
grep -i "ENOENT\|EACCES\|denied" /tmp/strace.log* | head -50
```

### 3. Comparar filesystem CageFS vs real

```bash
# Como root
# Ver qué ve el usuario dentro de CageFS
cagefsctl --enter ingeniusvirtual ls -la /usr/bin/ | grep -E "zip|tar|gzip"

# Comparar con filesystem real
ls -la /usr/bin/ | grep -E "zip|tar|gzip"
```

---

## 📊 VERIFICACIÓN POST-CONFIGURACIÓN

Después de aplicar la solución:

### 1. Verificar binarios disponibles

```bash
# Como ingeniusvirtual
cd /home/ingeniusvirtual/public_html

php -r "echo shell_exec('which zip') . PHP_EOL;"
php -r "echo shell_exec('which tar') . PHP_EOL;"
php -r "echo shell_exec('which gzip') . PHP_EOL;"
```

### 2. Verificar permisos de moodledata

```bash
# Como ingeniusvirtual
ls -la /home/ingeniusvirtual/moodledata/temp/backup
touch /home/ingeniusvirtual/moodledata/temp/backup/test.txt
rm /home/ingeniusvirtual/moodledata/temp/backup/test.txt
```

### 3. Probar compresión/descompresión

```bash
# Como ingeniusvirtual
cd /tmp
echo "test" > test.txt
zip test.zip test.txt
unzip -t test.zip
tar czf test.tar.gz test.txt
tar tzf test.tar.gz
rm test.*
```

### 4. Ejecutar copia de curso

Crear una copia desde Moodle y verificar:

```sql
-- Ver progreso
SELECT id, FROM_UNIXTIME(timestarted) as inicio,
       TIMESTAMPDIFF(MINUTE, FROM_UNIXTIME(timestarted), NOW()) as minutos,
       faildelay
FROM mdl_task_adhoc
WHERE classname = '\\core\\task\\asynchronous_copy_task'
ORDER BY id DESC LIMIT 5;
```

---

## 🎯 CONFIGURACIÓN ÓPTIMA FINAL

Después de solucionar, esta es la configuración recomendada:

### PHP (php.ini)
```ini
max_execution_time = 7200  # 2 horas para copias grandes
memory_limit = 2048M
max_input_time = 7200
```

### CloudLinux (LVE)
```
# Límites actuales están bien:
CPU: 650%
RAM: 24GB
I/O: 1.21GB/s
```

### CageFS
```bash
# Configurado con binarios necesarios:
- zip/unzip
- tar/gzip/bzip2
- PHP completo con extensiones
- Acceso a directorios temp
```

### Moodle (config.php)
```php
// Optimización CLI
if (PHP_SAPI === 'cli') {
    @ini_set('max_execution_time', 0);
    @ini_set('memory_limit', '2G');
}

// Timeout de tareas adhoc
$CFG->task_adhoc_max_runtime = 7200;
```

---

## 📋 RESUMEN DE CAUSAS DESCARTADAS

Durante la investigación se descartaron:

- ❌ Límites LVE (son excelentes: 24GB RAM, 1.21GB/s I/O)
- ❌ Timeout de PHP (aunque 90s es bajo, no era la causa principal)
- ❌ Hook de Moodle 4.5 (era sospechoso pero no la causa)
- ❌ Problema de código (funcionaba antes, el código es correcto)
- ✅ **CageFS bloqueando binarios/filesystem** ← CAUSA REAL

---

## 🔄 SI NECESITAS REACTIVAR CAGEFS DESPUÉS

```bash
# Como root
# Reactivar para el usuario
cagefsctl --enable ingeniusvirtual
cagefsctl --remount ingeniusvirtual

# Verificar estado
cagefsctl --list-enabled | grep ingeniusvirtual
```

---

## 📞 SOPORTE ADICIONAL

Si después de aplicar Solución 1 sigue sin funcionar:

1. Capturar logs:
```bash
# Antes de probar
tail -f /var/log/messages > /tmp/cagefs_debug.log &
TAIL_PID=$!

# Ejecutar copia
su - ingeniusvirtual -c "cd /home/ingeniusvirtual/public_html && /opt/cpanel/ea-php81/root/usr/bin/php admin/cli/adhoc_task.php --classname=\\\\core\\\\task\\\\asynchronous_copy_task --execute"

# Detener log
kill $TAIL_PID
cat /tmp/cagefs_debug.log
```

2. Contactar soporte de CloudLinux con:
   - Logs de /var/log/messages
   - Salida de `cagefsctl --list-enabled`
   - Contenido de `/etc/cagefs/conf.d/`
   - Descripción del problema (Moodle async backup fails)

---

## ✅ ÉXITO CONFIRMADO

Si las copias funcionan con CageFS desactivado:
- ✅ Problema identificado: CageFS
- ✅ Solución: Configurar CageFS correctamente (Solución 1)
- ✅ Alternativa: Deshabilitar solo para ingeniusvirtual (Solución 2)

**NO dejes CageFS desactivado globalmente** - afecta la seguridad de todos los usuarios.
