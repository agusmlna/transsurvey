<?php
namespace App\Http\Controllers;
use App\Models\{Client,Survey};
use App\Services\ReportService;
use Illuminate\Http\Request;
class ReportController {
 public function index(Request $r,ReportService $reports){$reports->validateFilters($r);return view('reports.index',$reports->summary($r)+['clients'=>Client::orderBy('name')->get(),'surveys'=>Survey::latest()->get()]);}
 public function csv(Request $r,ReportService $reports){$reports->validateFilters($r);return response()->streamDownload(function()use($r,$reports){$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['Klien','Proyek','Kuesioner','Tanggal','Skor','Persentase','Kategori','Pertanyaan','Jawaban','Komentar'],',','"','');$reports->responses($r)->orderBy('id')->chunkById(100,function($rows)use($out){foreach($rows as $row){foreach($row->answers as $a){$cells=[$row->client->name,$row->client->project,$row->survey->title,$row->submitted_at->toIso8601String(),$row->score,$row->score===null?'':round($row->score/5*100,2),$a->category,$a->question_text,$a->value,$a->comment];$cells=array_map(function($v){$s=(string)$v;return preg_match('/^[=+\-@\t\r\n]/',$s)?"'".$s:$s;},$cells);fputcsv($out,$cells,',','"','');}}});fclose($out);},'laporan-suara-'.today()->format('Y-m-d').'.csv',['Content-Type'=>'text/csv; charset=UTF-8']);}
}
