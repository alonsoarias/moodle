# SOLUCIÓN: Bloqueo por nuevo hook en Moodle 4.5

## 🎯 CAUSA RAÍZ IDENTIFICADA

**Código nuevo en Moodle 4.5** (commit MDL-83479, noviembre 2024):

```php
// Líneas 132-133 de lib/classes/task/asynchronous_copy_task.php
$hook = new before_copy_course_execute($plan, $copyinfo);
di::get(manager::class)->dispatch($hook);
```

**Este hook NO existía en versiones anteriores**, por eso funcionaba antes.

### ¿Por qué causa bloqueos?

1. **Plugin con callback defectuoso:** Si algún plugin registra un callback para `before_copy_course_execute` que:
   - No retorna
   - Tiene un loop infinito
   - Lanza excepciones no capturadas
   - Tiene operaciones bloqueantes

2. **Sistema DI no inicializado:** El sistema de Dependency Injection puede no estar correctamente inicializado en contexto CLI

3. **Error silencioso:** El hook falla pero no se reporta error visible

---

## 🔍 DIAGNÓSTICO

### 1. Verificar si hay plugins usando el hook

```bash
su - ingeniusvirtual
cd /home/ingeniusvirtual/public_html

# Buscar callbacks del hook
grep -r "before_copy_course_execute" --include="*.php" . 2>/dev/null | grep -v "vendor\|node_modules\|backup/classes/hook"

# Si encuentra resultados, esos plugins son sospechosos
```

### 2. Verificar plugins instalados

```sql
-- Conectar a MySQL
mysql -u usuario_bd -p nombre_bd

-- Ver plugins activos
SELECT component, name, version
FROM mdl_config_plugins
WHERE name = 'version'
ORDER BY component;

-- Ver hooks registrados (si existen)
SELECT * FROM mdl_hook_handlers
WHERE hookname LIKE '%copy%';
```

### 3. Ver logs del sistema

```bash
# Como root o ingeniusvirtual
tail -200 /home/ingeniusvirtual/public_html/error_log | grep -i "hook\|dispatch\|di\|dependency"
```

---

## 💊 SOLUCIÓN 1: FIX TEMPORAL (Agregar manejo de errores)

Este fix agrega try/catch alrededor del hook para que si falla, no bloquee la copia:

```bash
# Como root, descargar y ejecutar el script
cd /tmp
wget https://raw.githubusercontent.com/alonsoarias/moodle/claude/moodle-async-copy-task-debug-011CUjCHTQpzft9PbHZokZue/apply_hook_fix.sh
chmod +x apply_hook_fix.sh
./apply_hook_fix.sh
```

O hazlo manualmente:

```bash
# Como root
cd /home/ingeniusvirtual/public_html/lib/classes/task

# Backup
cp asynchronous_copy_task.php asynchronous_copy_task.php.backup

# Editar
nano asynchronous_copy_task.php
```

Busca las líneas 131-133 (aproximadamente):

```php
// Create and dispatch a hook to allow interaction with the task immediately prior to execution.
$hook = new before_copy_course_execute($plan, $copyinfo);
di::get(manager::class)->dispatch($hook);
```

Reemplázalas por:

```php
// Create and dispatch a hook to allow interaction with the task immediately prior to execution.
// TEMPORARY FIX: Add error handling to hook dispatch
try {
    $hook = new before_copy_course_execute($plan, $copyinfo);
    di::get(manager::class)->dispatch($hook);
} catch (\Exception $e) {
    mtrace('Course copy: WARNING - Hook dispatch failed: ' . $e->getMessage());
    // Continue execution even if hook fails
} catch (\Error $e) {
    mtrace('Course copy: WARNING - Hook dispatch error: ' . $e->getMessage());
    // Continue execution even if hook fails
}
```

Guarda (`Ctrl+O`, `Enter`, `Ctrl+X`).

### Probar:

```bash
su - ingeniusvirtual
cd /home/ingeniusvirtual/public_html

/opt/cpanel/ea-php81/root/usr/bin/php \
  admin/cli/adhoc_task.php \
  --classname=\\core\\task\\asynchronous_copy_task \
  --execute --showdebugging 2>&1 | tee /tmp/test_after_fix.log
```

**Si funciona:** El hook era el problema. Busca qué plugin lo causa.

**Si sigue bloqueando:** Hay otro problema adicional.

---

## 💊 SOLUCIÓN 2: DESHABILITAR HOOK COMPLETAMENTE (MÁS AGRESIVO)

Si el fix anterior no funciona, comenta completamente el hook:

```php
// Create and dispatch a hook to allow interaction with the task immediately prior to execution.
// DISABLED: Hook causes hanging - investigating
// $hook = new before_copy_course_execute($plan, $copyinfo);
// di::get(manager::class)->dispatch($hook);
```

---

## 💊 SOLUCIÓN 3: Actualizar a versión corregida (si existe)

Verificar si hay un bug report en Moodle tracker:

```bash
# Buscar en tracker.moodle.org
# MDL-83479 (el commit que agregó el hook)
# Buscar issues relacionados con "async copy" después de noviembre 2024
```

---

## 🔧 SOLUCIÓN 4: Identificar plugin problemático

Si el fix temporal funciona pero quieres identificar el plugin:

```bash
# Deshabilitar plugins uno por uno
# Ver plugins instalados
ls -la /home/ingeniusvirtual/public_html/local/
ls -la /home/ingeniusvirtual/public_html/mod/
ls -la /home/ingeniusvirtual/public_html/theme/

# Para cada plugin sospechoso:
# 1. Renombrar temporalmente
# 2. Purgar cachés: php admin/cli/purge_caches.php
# 3. Probar copia
# 4. Si funciona, ese plugin es el culpable
```

---

## 📊 MONITOREO POST-FIX

Después de aplicar el fix, monitorea:

```bash
# Ver si aparece el warning del hook
tail -f /home/ingeniusvirtual/public_html/error_log | grep -i "hook dispatch"

# Durante una copia, en otra terminal:
watch -n 2 "ps aux | grep asynchronous_copy_task | grep -v grep"
```

---

## 🎯 REPORTE A MOODLE

Si el problema es el hook, deberías reportarlo a Moodle:

1. Ir a https://tracker.moodle.org
2. Buscar si ya existe issue relacionado con MDL-83479
3. Si no existe, crear nuevo issue:
   - Componente: Backup and restore
   - Affected versions: 4.5
   - Descripción: "Hook before_copy_course_execute causes async copy to hang"
   - Pasos para reproducir
   - Información de tu entorno (PHP 8.1, LiteSpeed, CloudLinux)

---

## 📋 RESUMEN

**Antes de Moodle 4.5:**
- No había hook `before_copy_course_execute`
- Copia asíncrona funcionaba con 90s de timeout

**Moodle 4.5 (nov 2024):**
- Se agregó hook en línea 132-133
- Hook puede fallar o bloquearse
- Causa: Plugin defectuoso o DI no inicializado

**Solución:**
1. Aplicar fix temporal con try/catch
2. Identificar plugin problemático
3. Reportar a Moodle si es bug del core

---

## 🔄 PARA REVERTIR EL FIX

```bash
# Como root
cp /home/ingeniusvirtual/public_html/lib/classes/task/asynchronous_copy_task.php.backup \
   /home/ingeniusvirtual/public_html/lib/classes/task/asynchronous_copy_task.php
```

---

## ✅ VERIFICACIÓN FINAL

Después del fix:

```bash
# 1. Limpiar tareas bloqueadas
mysql -u root -p nombre_bd -e "UPDATE mdl_task_adhoc SET timestarted=NULL, faildelay=0 WHERE classname='\\\\core\\\\task\\\\asynchronous_copy_task' AND timestarted IS NOT NULL"

# 2. Crear copia de curso desde Moodle

# 3. Ver progreso
watch -n 5 "mysql -u root -p nombre_bd -e \"SELECT id, FROM_UNIXTIME(timestarted) as started, TIMESTAMPDIFF(MINUTE, FROM_UNIXTIME(timestarted), NOW()) as mins FROM mdl_task_adhoc WHERE classname='\\\\\\\\core\\\\\\\\task\\\\\\\\asynchronous_copy_task' AND timestarted IS NOT NULL\""

# 4. Ver si hay warning del hook en logs
tail -f /home/ingeniusvirtual/public_html/error_log | grep -i hook
```

Si la copia se completa → **El hook era el problema**
Si sigue bloqueando → **Hay algo más**
