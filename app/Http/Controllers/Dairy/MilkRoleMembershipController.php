<?php

namespace App\Http\Controllers\Dairy;

use App\Http\Controllers\Controller;
use App\Models\FactoryOperator;
use App\Models\TransporterCompany;
use App\Models\TransporterDriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MilkRoleMembershipController extends Controller
{
    public function transportation(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $drivers = TransporterDriver::query()
            ->where('user_id', $userId)
            ->with(['transporter:uuid,name,approval_status,is_active'])
            ->get(['uuid', 'transporter_company_uuid', 'approval_status', 'is_active']);
        $companies = TransporterCompany::query()
            ->where('manager_user_id', $userId)
            ->get(['uuid', 'name', 'approval_status', 'approval_remarks', 'is_active']);

        return response()->json([
            'status' => true,
            'data' => ['drivers' => $drivers, 'companies' => $companies],
        ]);
    }

    public function factories(Request $request): JsonResponse
    {
        $operators = FactoryOperator::query()
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->with(['factory:uuid,name,factory_code,approval_status,is_active'])
            ->get(['uuid', 'factory_uuid', 'is_active']);

        return response()->json(['status' => true, 'data' => $operators]);
    }
}
