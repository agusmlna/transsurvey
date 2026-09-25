<?php
namespace App\Http\Controllers;
use App\Services\ReportService;
use App\Models\{Client,Survey};
use Illuminate\Http\Request;
class DashboardController {
 public function __invoke(Request $r,ReportService $reports){$reports->validateFilters($r);return view('dashboard.index',$reports->summary($r)+['clients'=>Client::orderBy('name')->get(),'surveys'=>Survey::latest()->get()]);}
}
