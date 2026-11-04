# Revisar y subir únicamente los cambios de Irene

La base del equipo ya está publicada en https://github.com/HugoFernandezz/Zapatero, main fe1307d. Zapatero-Equipo conserva ese historial: upstream apunta a Hugo y origin al fork de Irene. La rama propia es codex/irene-politicas-telegram. No importar el ZIP inicial ni crear un commit con todo el proyecto.

## Revisar el alcance

```powershell
git status --short
git diff upstream/main --stat
git diff upstream/main
```

El diff debe contener las políticas, el pie, el contrato de Telegram retirado, las pruebas, el workflow de verificación, los scripts y los documentos descritos en cambios-irene.md. No incluye la creación de la API, del back-office ni de los tres workflows originales: esos commits son de los compañeros y se mantienen en la historia.

Los paquetes nuevos de SOLO-CAMBIOS-IRENE se comparan con fe1307d. Un parche generado contra el ZIP antiguo no debe aplicarse a esta base. Para aplicar el parche en otra copia limpia, utilizar primero git apply --check y revisar cualquier conflicto sin sobrescribir archivos completos para evitarlo.

## Publicar la rama propia

Antes de subir, revisar los archivos preparados con git diff --cached. No utilizar git add . sin comprobar la lista: .env, bases, respaldos y tmp están fuera de la entrega. Las capturas no deben contener tokens, contraseñas ni enlaces de Telegram.

```powershell
git push -u origin codex/irene-politicas-telegram
```

Para integrar en el repositorio del equipo, abrir una solicitud de cambios hacia Hugo:main utilizando esta rama y describir solo sus mejoras y verificaciones. No se hace force push ni se modifica main automáticamente.

La configuración de Render ya está publicada por separado en codex/despliegue-n8n-irene del fork de Irene: únicamente render.yaml, n8n/Dockerfile.render y n8n/render-start.sh, con último commit 9dbfdaf. No requiere subir el resto de la carpeta para que n8n siga funcionando.

La actualización realizada por WebFTP del hosting y el diff de autoría de GitHub cumplen propósitos distintos: el hosting necesita la base actual del equipo para ejecutar el back-office; el commit de Irene contiene solo las diferencias sobre esa base.
