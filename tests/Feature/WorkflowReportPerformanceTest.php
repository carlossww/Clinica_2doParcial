<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsVidaSalud;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class WorkflowReportPerformanceTest extends TestCase
{
    use RefreshDatabase,SeedsVidaSalud;

    public function test_five_reports_finish_within_five_seconds_on_a_documented_synthetic_volume(): void
    {
        $this->seedVidaSalud();
        $payload = $this->appointmentPayload();
        $doctor = $this->account('medico');
        $reception = $this->account('recepcion');
        $lab = DB::table('usuario')->where('rol_id', DB::table('rol')->where('nombre', 'Laboratorista')->value('id'))->value('id');
        $analysis = DB::table('analisis_laboratorio')->orderBy('id')->limit(2)->pluck('id');
        TestDatabaseSafety::assertApplication($this->app);
        // Importación de un volumen histórico sintético. Todas las consultas medidas usan las reglas activas.
        DB::transaction(function () use ($payload, $doctor, $reception, $lab, $analysis) {
            $previous = DB::selectOne('show session_replication_role')->session_replication_role;
            DB::statement('set local session_replication_role=replica');
            DB::statement("insert into paciente(ci,nombres,apellidos,fecha_nacimiento,telefono,activo) select 'PERF-'||g,'Paciente sintético','Rendimiento '||g,'1990-01-01'::date,'00000000',true from generate_series(1,5000) g");
            DB::statement("insert into cita(paciente_id,medico_id,especialidad_id,inicio,fin,estado,creada_por,modificada_por)
                    select ?,?,?,'2020-01-01 09:00-04'::timestamptz+g*interval '1 hour','2020-01-01 09:30-04'::timestamptz+g*interval '1 hour','Atendida',?,? from generate_series(1,10000) g",
                [$payload['paciente_id'], $payload['medico_id'], $payload['especialidad_id'], $reception->id, $reception->id]);
            DB::statement('insert into consulta(cita_id,registrada_por,fecha_atencion) select id,?,inicio from cita order by id limit 2000', [$doctor->id]);
            DB::statement("insert into orden_laboratorio(consulta_id,solicitada_por,estado) select id,?,'Completada' from consulta", [$doctor->id]);
            DB::statement('insert into orden_analisis(orden_id,analisis_id) select id,?::bigint from orden_laboratorio union all select id,?::bigint from orden_laboratorio', [$analysis[0], $analysis[1]]);
            DB::statement("insert into resultado_laboratorio(orden_analisis_id,laboratorista_id,resultado) select id,?,'Dato sintético de rendimiento' from orden_analisis", [$lab]);
            DB::selectOne("select set_config('session_replication_role',?,true)", [$previous]);
        });
        $this->actingAs($this->account('admin'));
        $measurements = [];
        foreach (['citas' => 10000, 'pacientes' => 5001, 'atenciones' => 2000, 'ordenes' => 2000, 'resultados' => 4000] as $type => $count) {
            for ($iteration = 0; $iteration < 3; $iteration++) {
                $start = hrtime(true);
                $this->get(route('reports.index', ['tipo' => $type]))->assertOk()->assertViewHas('totals', fn ($totals) => $totals['Registros'] === $count);
                $elapsed = (hrtime(true) - $start) / 1e6;
                $measurements[$type][] = $elapsed;
                $this->assertLessThan(5000, $elapsed, 'Reporte '.$type.' excede RNF16 en el entorno sintético local.');
            }
        }
        if (is_dir(base_path('.runtime/diez-cu'))) {
            file_put_contents(base_path('.runtime/diez-cu/performance.json'), json_encode(['fecha_bolivia' => now('America/La_Paz')->toIso8601String(), 'volumen' => ['citas' => 10000, 'pacientes' => 5001, 'atenciones' => 2000, 'ordenes' => 2000, 'resultados' => 4000], 'milisegundos' => $measurements], JSON_PRETTY_PRINT));
        }
    }
}
