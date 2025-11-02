#!/bin/bash
################################################################################
# Script para aplicar fix temporal del hook en asynchronous_copy_task
# Este es un FIX TEMPORAL para probar si el hook es la causa del bloqueo
################################################################################

MOODLE_FILE="/home/ingeniusvirtual/public_html/lib/classes/task/asynchronous_copy_task.php"

if [ ! -f "$MOODLE_FILE" ]; then
    echo "ERROR: No se encuentra el archivo: $MOODLE_FILE"
    exit 1
fi

echo "=============================================================================="
echo "FIX TEMPORAL: Agregar try/catch al hook dispatch"
echo "=============================================================================="
echo ""
echo "Archivo: $MOODLE_FILE"
echo ""

# Crear backup
BACKUP_FILE="${MOODLE_FILE}.backup_$(date +%Y%m%d_%H%M%S)"
cp "$MOODLE_FILE" "$BACKUP_FILE"
echo "✓ Backup creado: $BACKUP_FILE"
echo ""

# Verificar si ya fue aplicado
if grep -q "TEMPORARY FIX.*Hook dispatch" "$MOODLE_FILE"; then
    echo "⚠️  El fix ya fue aplicado anteriormente"
    exit 0
fi

# Aplicar el fix usando sed
# Buscar las líneas del hook y agregar try/catch
sed -i.bak '/Create and dispatch a hook/,/di::get(manager::class)->dispatch($hook);/ {
    /Create and dispatch a hook/ {
        a\        // TEMPORARY FIX: Add error handling to hook dispatch
    }
    /\$hook = new before_copy_course_execute/ {
        i\        try {
    }
    /di::get(manager::class)->dispatch($hook);/ {
        a\        } catch (\\Exception $e) {\
            mtrace('"'"'Course copy: WARNING - Hook dispatch failed: '"'"' . $e->getMessage());\
            // Continue execution even if hook fails\
        }
    }
}' "$MOODLE_FILE"

echo "✓ Fix aplicado"
echo ""
echo "Cambios realizados:"
echo "---"
grep -A 10 "TEMPORARY FIX" "$MOODLE_FILE" || echo "Error mostrando cambios"
echo "---"
echo ""
echo "Para revertir:"
echo "  cp $BACKUP_FILE $MOODLE_FILE"
echo ""
echo "=============================================================================="
echo "Ahora prueba ejecutar una copia de curso"
echo "=============================================================================="
