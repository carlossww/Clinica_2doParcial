const dayNames = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

document.querySelectorAll('[data-schedule-editor]').forEach((form) => {
    const doctor = form.elements.namedItem('medico_id');
    const start = form.elements.namedItem('hora_inicio');
    const end = form.elements.namedItem('hora_fin');
    const duration = form.elements.namedItem('duracion_minutos');
    const active = form.querySelector('input[type="checkbox"][name="activo"]');
    const days = [...form.querySelectorAll('input[name="dias_semana[]"]')];
    const singleDay = form.elements.namedItem('dia_semana');
    const put = (name, value) => { form.querySelector(`[data-summary-${name}]`).textContent = value; };
    const minutes = (time) => {
        if (!/^\d{2}:\d{2}$/.test(time)) return NaN;
        const [hours, mins] = time.split(':').map(Number);
        return hours * 60 + mins;
    };
    const update = () => {
        const selected = days.filter((day) => day.checked).map((day) => Number(day.value));
        const dayText = singleDay ? singleDay.options[singleDay.selectedIndex]?.text :
            (selected.join(',') === '1,2,3,4,5' ? 'Lunes a viernes' : selected.length === 7 ? 'Todos los días' : selected.map((day) => dayNames[day - 1]).join(' · '));
        put('doctor', doctor.dataset.doctorLabel || (doctor.value ? doctor.options[doctor.selectedIndex].text : 'Selecciona un médico'));
        put('days', dayText || 'Elige los días de atención');
        put('time', `${start.value || '––:––'} – ${end.value || '––:––'}`);
        const span = minutes(end.value) - minutes(start.value);
        const size = Number(duration.value);
        const valid = span > 0 && size > 0 && span % size === 0;
        put('detail', !active.checked ? 'Horario inactivo · No ofrece turnos' : valid ? `${span / size} turnos por día · ${size} min por cita` : `Citas de ${size || '…'} minutos`);
        const message = form.querySelector('[data-summary-message]');
        message.hidden = !start.value || !end.value || valid;
        message.textContent = span <= 0 ? 'La hora de fin debe ser posterior al inicio.' : 'Ajusta las horas para que quepan citas completas.';
    };
    form.querySelector('[data-day-presets]')?.removeAttribute('hidden');
    form.querySelectorAll('[data-days]').forEach((button) => button.addEventListener('click', () => {
        const chosen = button.dataset.days.split(',');
        days.forEach((day) => { day.checked = chosen.includes(day.value); });
        update();
    }));
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
});
