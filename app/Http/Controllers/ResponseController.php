<?php
namespace App\Http\Controllers;
use App\Models\{SurveyResponse,Client,Survey};
use App\Services\ReportService;
use Illuminate\Http\Request;
class ResponseController {
 public function index(Request $r,ReportService $reports){$reports->validateFilters($r);return view('responses.index',['responses'=>$reports->responses($r)->latest('submitted_at')->paginate(20)->withQueryString(),'clients'=>Client::orderBy('name')->get(),'surveys'=>Survey::latest()->get()]);}
 public function show(SurveyResponse $response){return view('responses.show',['response'=>$response->load(['answers','client','survey','followUp'])]);}
}
