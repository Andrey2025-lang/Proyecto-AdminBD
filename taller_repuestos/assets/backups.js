document.addEventListener('submit',event=>{
    const form=event.target.closest('[data-backup-form]');
    if(!form)return;
    const button=form.querySelector('button');
    if(button.disabled){event.preventDefault();return;}
    button.disabled=true;button.classList.add('is-loading');
    form.querySelector('[role=status]').textContent='Creando y comprobando la copia. Espere la confirmación; si se pierde la conexión, consulte el historial antes de reintentar.';
});
