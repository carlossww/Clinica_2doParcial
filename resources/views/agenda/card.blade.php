<article class="agenda-card" aria-label="Cita {{ $row->codigo }}">
<div class="agenda-time"><strong>{{ \App\Modules\Shared\Clinic::date($row->inicio)->format('H:i') }}</strong><span>hasta {{ \App\Modules\Shared\Clinic::date($row->fin)->format('H:i') }}</span><span>{{ \App\Modules\Shared\Clinic::date($row->inicio)->format('d/m/Y') }}</span></div>
<div class="agenda-person"><h3>{{ $row->paciente }}</h3><p>{{ $row->medico }}{{ $row->medico_habilitado ? '' : ' (inactivo)' }} · {{ $row->especialidad }}</p><a class="agenda-code" href="{{ route('appointments.show',$row->id) }}">{{ $row->codigo }}</a>
@if($showReason)<p class="agenda-reason"><strong>Motivo:</strong> {{ $row->motivo_consulta ?: '—' }}</p>@endif
</div>
<div class="agenda-status"><x-status :value="$row->estado" /></div>
<nav class="agenda-actions" aria-label="Acciones de la cita {{ $row->codigo }}">
<a class="button button-small" href="{{ route('appointments.show',$row->id) }}">Ver detalle</a>
@if($canManage && in_array($row->estado,['Programada','Confirmada']))
@if($row->estado === 'Programada')<a class="button button-small" href="{{ route('appointments.edit',['id'=>$row->id,'accion'=>'confirmar']) }}">Confirmar asistencia</a>@endif
<a class="button button-small" href="{{ route('appointments.edit',['id'=>$row->id,'accion'=>'reprogramar']) }}">Reprogramar</a>
<a class="button button-small" href="{{ route('appointments.edit',['id'=>$row->id,'accion'=>'cancelar']) }}">Cancelar</a>
@if(\App\Modules\Shared\Clinic::date($row->fin)->lte(now('America/La_Paz')))<a class="button button-small" href="{{ route('appointments.edit',['id'=>$row->id,'accion'=>'inasistencia']) }}">Marcar inasistencia</a>@endif
@endif
@if(auth()->user()->isDoctor() && auth()->user()->hasPermission('historia.consultar') && in_array($row->estado,['Programada','Confirmada','Atendida']))<a class="button button-small" href="{{ route('clinical.show',$row->paciente_id) }}">Abrir historia clínica</a>@endif
</nav>
</article>
