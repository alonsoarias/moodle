# ⚠️ IMPORTANTE: Gestión de Versiones de PHP en cPanel

## Problema Crítico Detectado

El script original `configure_cagefs_for_moodle.sh` tenía un **defecto importante**: estaba hardcodeando las rutas de **ea-php81** específicamente.

### ¿Por qué es un problema?

En servidores cPanel con CloudLinux y CageFS:

1. **Las versiones de PHP cambian frecuentemente**
   - Actualizaciones de seguridad
   - Upgrades de versión mayor (8.1 → 8.2 → 8.3)
   - Cambios de configuración por el administrador

2. **CageFS virtualiza el filesystem**
   - Solo los paths configurados en `/etc/cagefs/conf.d/*.cfg` son accesibles
   - Si cambias de `ea-php81` a `ea-php82`, las rutas hardcodeadas dejan de funcionar
   - El problema de async copy volvería a aparecer

3. **Múltiples versiones pueden coexistir**
   - cPanel permite tener instaladas varias versiones simultáneamente
   - Cada sitio puede usar una versión diferente
   - La versión por defecto puede cambiar

### Ejemplo del Problema

**Script original (INCORRECTO):**
```bash
# /etc/cagefs/conf.d/moodle.cfg
paths=/opt/cpanel/ea-php81/root/usr/bin        # ❌ Hardcoded
paths=/opt/cpanel/ea-php81/root/usr/lib64      # ❌ Hardcoded
```

**¿Qué pasa si actualizas a PHP 8.2?**
- Tu Moodle ahora usa `/opt/cpanel/ea-php82/root/usr/bin/php`
- Pero CageFS solo tiene configurado ea-php81
- Las tareas async vuelven a fallar
- Necesitas editar manualmente el archivo y reiniciar CageFS

---

## ✅ Solución: Script v2.0 Agnóstico de Versión

He creado `configure_cagefs_for_moodle_v2.sh` que:

### 1. Detecta Automáticamente la Versión de PHP

El script intenta múltiples métodos para detectar qué versión usa tu Moodle:

```bash
# Método 1: Desde config.php de Moodle
grep -i "php.*cli" config.php

# Método 2: Desde .htaccess
grep "AddHandler.*ea-php" .htaccess

# Método 3: Desde API de cPanel
whmapi1 php_get_vhost_versions user="ingeniusvirtual"

# Método 4: Desde archivo de usuario de cPanel
grep "PHP" /var/cpanel/users/ingeniusvirtual

# Método 5: Ejecutando PHP directamente
/opt/cpanel/ea-php*/root/usr/bin/php -v
```

### 2. Configura TODAS las Versiones Instaladas

En lugar de hardcodear una versión, el script agrega **todas** las versiones de PHP que encuentre:

```bash
# /etc/cagefs/conf.d/moodle.cfg (generado automáticamente)

# ea-php74
paths=/opt/cpanel/ea-php74/root/usr/bin
paths=/opt/cpanel/ea-php74/root/usr/lib64
paths=/opt/cpanel/ea-php74/root/usr/lib64/php/modules

# ea-php80
paths=/opt/cpanel/ea-php80/root/usr/bin
paths=/opt/cpanel/ea-php80/root/usr/lib64
paths=/opt/cpanel/ea-php80/root/usr/lib64/php/modules

# ea-php81
paths=/opt/cpanel/ea-php81/root/usr/bin
paths=/opt/cpanel/ea-php81/root/usr/lib64
paths=/opt/cpanel/ea-php81/root/usr/lib64/php/modules

# ea-php82
paths=/opt/cpanel/ea-php82/root/usr/bin
paths=/opt/cpanel/ea-php82/root/usr/lib64
paths=/opt/cpanel/ea-php82/root/usr/lib64/php/modules

# ... etc para todas las versiones instaladas
```

### 3. Ventajas de Este Enfoque

✅ **A prueba de futuro:** Si instalas PHP 8.3 mañana, seguirá funcionando
✅ **Sin mantenimiento:** No necesitas editar configs al cambiar de versión
✅ **Multi-sitio:** Si tienes varios Moodles con diferentes versiones de PHP, todos funcionan
✅ **Fallback automático:** Si algo falla con una versión, las otras siguen disponibles
✅ **Compatible con cPanel:** Sigue la convención de cPanel de tener múltiples versiones

---

## 📊 Comparación de Scripts

| Característica | Script v1.0 (Viejo) | Script v2.0 (Nuevo) |
|----------------|---------------------|---------------------|
| Detecta versión de PHP | ❌ No (hardcoded 81) | ✅ Sí (5 métodos) |
| Múltiples versiones PHP | ❌ Solo ea-php81 | ✅ Todas las instaladas |
| A prueba de actualizaciones | ❌ No | ✅ Sí |
| Requiere reconfiguración | ✅ Sí (cada upgrade) | ❌ No |
| Compatible con MultiPHP | ⚠️ Parcial | ✅ Total |
| Muestra versiones disponibles | ❌ No | ✅ Sí |
| Verifica cada versión | ❌ No | ✅ Sí |

---

## 🚀 Cómo Usar el Script v2.0

```bash
# 1. Descargar desde el repositorio
cd /root
git clone https://github.com/alonsoarias/moodle.git moodle-fix
cd moodle-fix
git checkout claude/moodle-async-copy-task-debug-011CUjCHTQpzft9PbHZokZue

# 2. Dar permisos de ejecución
chmod +x configure_cagefs_for_moodle_v2.sh

# 3. Ejecutar (como root)
./configure_cagefs_for_moodle_v2.sh
```

### Salida Esperada

El script te mostrará:

```
PASO 0: Detectando versión de PHP para ingeniusvirtual...
  ✓ Detectado desde .htaccess: ea-php81

✓ Usando PHP: ea-php81
  Ruta base: /opt/cpanel/ea-php81/root

Versiones de PHP instaladas en el servidor:
  - ea-php74 (7.4.33)
  - ea-php80 (8.0.30)
  - ea-php81 (8.1.29)
  - ea-php82 (8.2.23)
  - ea-php83 (8.3.11)

PASO 2: Creando configuración de Moodle para CageFS...
  Agregando todas las versiones de PHP al config...
  ✓ Agregado: ea-php74
  ✓ Agregado: ea-php80
  ✓ Agregado: ea-php81
  ✓ Agregado: ea-php82
  ✓ Agregado: ea-php83
```

---

## 🔄 Qué Hacer Si Ya Ejecutaste el Script v1.0

Si ya ejecutaste el script antiguo con rutas hardcodeadas:

### Opción A: Ejecutar el script v2.0 (RECOMENDADO)

El script v2.0 detectará que ya existe una configuración y creará un backup automático:

```bash
./configure_cagefs_for_moodle_v2.sh

# Verás:
#   ✓ Backup de configuración anterior creado
#   /etc/cagefs/conf.d/moodle.cfg.backup.20251102_143522
```

### Opción B: Editar manualmente el archivo existente

```bash
# 1. Backup
cp /etc/cagefs/conf.d/moodle.cfg /etc/cagefs/conf.d/moodle.cfg.backup

# 2. Editar el archivo
nano /etc/cagefs/conf.d/moodle.cfg

# 3. Agregar todas las versiones de PHP:
for PHP_DIR in /opt/cpanel/ea-php*/root; do
    if [ -d "$PHP_DIR" ]; then
        PHP_VER=$(echo "$PHP_DIR" | grep -oP "ea-php[0-9]+")
        echo "" >> /etc/cagefs/conf.d/moodle.cfg
        echo "# $PHP_VER" >> /etc/cagefs/conf.d/moodle.cfg
        echo "paths=$PHP_DIR/usr/bin" >> /etc/cagefs/conf.d/moodle.cfg
        echo "paths=$PHP_DIR/usr/lib64" >> /etc/cagefs/conf.d/moodle.cfg
        echo "paths=$PHP_DIR/usr/lib64/php/modules" >> /etc/cagefs/conf.d/moodle.cfg
        echo "paths=$PHP_DIR/etc" >> /etc/cagefs/conf.d/moodle.cfg
    fi
done

# 4. Aplicar cambios
cagefsctl --force-update
cagefsctl --remount-all
```

---

## 📝 Verificación Post-Configuración

Después de ejecutar el script v2.0, verifica que todas las versiones sean accesibles:

```bash
# Como root, entrar al cage del usuario
cagefsctl --enter ingeniusvirtual

# Dentro del cage, verificar:
ls -la /opt/cpanel/

# Deberías ver:
# drwxr-xr-x  ea-php74
# drwxr-xr-x  ea-php80
# drwxr-xr-x  ea-php81
# drwxr-xr-x  ea-php82
# drwxr-xr-x  ea-php83

# Verificar que PHP funcione:
/opt/cpanel/ea-php81/root/usr/bin/php -v
/opt/cpanel/ea-php82/root/usr/bin/php -v

# Salir del cage
exit
```

---

## 🎯 Recomendaciones Finales

### Para el Administrador del Servidor:

1. **USA SIEMPRE el script v2.0** - No el v1.0
2. **Ejecuta el script cada vez que instales una nueva versión de PHP** (opcional, pero recomendado)
3. **Documenta qué versión usa cada sitio** - Para troubleshooting futuro

### Para Moodle en Producción:

1. **Prueba en staging primero** - Antes de cambiar la versión de PHP en producción
2. **Ten un rollback plan** - Guarda backups de:
   - Base de datos
   - moodledata
   - Configuración de CageFS
3. **Monitorea después del cambio** - Verifica que async copy siga funcionando

### Cuando Cambies de Versión de PHP:

```bash
# Antes del cambio
1. Anota la versión actual: ea-php81
2. Desactiva tareas cron temporalmente
3. Completa todas las copias pendientes

# Después del cambio
4. Verifica el nuevo PHP: /opt/cpanel/ea-php82/root/usr/bin/php -v
5. Prueba async copy: php admin/cli/adhoc_task.php ...
6. Si falla, verifica CageFS: cagefsctl --enter ingeniusvirtual
7. Si es necesario, re-ejecuta el script v2.0
8. Reactiva tareas cron
```

---

## 📚 Referencias

- **MultiPHP Manager en cPanel:** https://docs.cpanel.net/ea4/php/multiphp-manager/
- **CageFS Documentation:** https://docs.cloudlinux.com/cloudlinux_os_components/#cagefs
- **Moodle System Requirements:** https://docs.moodle.org/en/PHP

---

## ⚙️ Archivos Relacionados

- `configure_cagefs_for_moodle_v2.sh` - Script mejorado (USA ESTE)
- `configure_cagefs_for_moodle.sh` - Script original (OBSOLETO)
- `SOLUCION_DEFINITIVA_CAGEFS.md` - Documentación de la solución
- `RESUMEN_FINAL_INVESTIGACION.md` - Resumen completo de la investigación

---

## 🆘 Soporte

Si después de ejecutar el script v2.0 las copias async siguen fallando:

1. **Verifica que la nueva configuración se aplicó:**
   ```bash
   cat /etc/cagefs/conf.d/moodle.cfg | grep -c "ea-php"
   # Debería mostrar 5+ (una por cada versión instalada)
   ```

2. **Verifica que CageFS se actualizó:**
   ```bash
   cagefsctl --list-enabled | grep ingeniusvirtual
   # Si aparece, CageFS está activo

   cagefsctl --enter ingeniusvirtual which php
   # Debería mostrar la ruta de PHP
   ```

3. **Captura logs detallados:**
   ```bash
   su - ingeniusvirtual
   cd /home/ingeniusvirtual/public_html
   strace -o /tmp/strace.log -ff \
     /opt/cpanel/ea-php81/root/usr/bin/php \
     admin/cli/adhoc_task.php \
     --classname=\\core\\task\\asynchronous_copy_task \
     --execute 2>&1 | tee /tmp/async_copy.log

   # Buscar errores:
   grep -i "ENOENT\|EACCES\|denied" /tmp/strace.log* | head -50
   ```

---

**Última actualización:** 2025-11-02
**Versión del documento:** 1.0
**Script versión:** 2.0
