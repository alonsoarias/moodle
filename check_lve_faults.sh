#!/bin/bash
# Script para verificar faults LVE del usuario ingeniusvirtual

echo "=============================================================================="
echo "VERIFICACIÓN DE LÍMITES LVE ALCANZADOS - ingeniusvirtual"
echo "=============================================================================="
echo ""

# Verificar faults en las últimas 24 horas
echo "--- FAULTS EN LAS ÚLTIMAS 24 HORAS ---"
lveinfo --user=ingeniusvirtual --period=1d --show-columns=ep,pmem,vmem,io,iops,nproc 2>/dev/null

echo ""
echo "--- FAULTS EN LA ÚLTIMA HORA ---"
lveinfo --user=ingeniusvirtual --period=1h --show-columns=ep,pmem,vmem,io,iops,nproc 2>/dev/null

echo ""
echo "--- LÍMITES ACTUALES ---"
lvectl list | grep -A1 "ingeniusvirtual"

echo ""
echo "=============================================================================="
echo "INTERPRETACIÓN:"
echo "=============================================================================="
echo "- fEP: Entry Processes alcanzados (límite: 180)"
echo "- fPMem: Memoria física alcanzada (límite: 24GB)"
echo "- fVMem: Memoria virtual alcanzada (límite: 48GB)"
echo "- fIO: I/O alcanzado (límite: ~1.2MB/s) ← PROBABLE CAUSA SI HAY FAULTS"
echo "- fIOPS: IOPS alcanzados (límite: ~495K)"
echo "- fNproc: Número de procesos alcanzado (límite: 600)"
echo ""
echo "Si ves números > 0 en columnas con 'f', ese recurso se está agotando."
echo "=============================================================================="
