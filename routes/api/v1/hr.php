<?php

use App\Http\Controllers\Api\Hr\HrAttendanceController;
use App\Http\Controllers\Api\Hr\HrEmployeeController;
use App\Http\Controllers\Api\Hr\HrLeaveController;
use App\Http\Controllers\Api\Hr\HrOrgController;
use App\Http\Controllers\Api\Hr\HrPayrollController;
use App\Http\Controllers\Api\Hr\HrReportController;
use App\Http\Controllers\Api\Hr\HrTalentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'business.active', 'module:hr'])->prefix('hr')->group(function () {
    // Org
    Route::get('/departments', [HrOrgController::class, 'indexDepartments']);
    Route::post('/departments', [HrOrgController::class, 'storeDepartment']);
    Route::patch('/departments/{id}', [HrOrgController::class, 'updateDepartment'])->whereNumber('id');
    Route::delete('/departments/{id}', [HrOrgController::class, 'destroyDepartment'])->whereNumber('id');

    Route::get('/positions', [HrOrgController::class, 'indexPositions']);
    Route::post('/positions', [HrOrgController::class, 'storePosition']);
    Route::patch('/positions/{id}', [HrOrgController::class, 'updatePosition'])->whereNumber('id');
    Route::delete('/positions/{id}', [HrOrgController::class, 'destroyPosition'])->whereNumber('id');

    // Employees
    Route::get('/employees', [HrEmployeeController::class, 'index']);
    Route::post('/employees', [HrEmployeeController::class, 'store']);
    Route::get('/employees/{id}', [HrEmployeeController::class, 'show'])->whereNumber('id');
    Route::patch('/employees/{id}', [HrEmployeeController::class, 'update'])->whereNumber('id');
    Route::delete('/employees/{id}', [HrEmployeeController::class, 'destroy'])->whereNumber('id');
    Route::post('/employees/{id}/link-user', [HrEmployeeController::class, 'linkUser'])->whereNumber('id');
    Route::post('/employees/{id}/unlink-user', [HrEmployeeController::class, 'unlinkUser'])->whereNumber('id');

    // Attendance
    Route::post('/attendance/clock', [HrAttendanceController::class, 'clock']);
    Route::get('/attendance/events', [HrAttendanceController::class, 'events']);
    Route::get('/attendance/register', [HrAttendanceController::class, 'register']);
    Route::put('/attendance/days', [HrAttendanceController::class, 'correctDay']);
    Route::post('/attendance/import-timesheets', [HrAttendanceController::class, 'importTimesheets']);
    Route::get('/attendance/pos-shifts', [HrAttendanceController::class, 'posShifts']);

    // Leave
    Route::get('/leave/types', [HrLeaveController::class, 'indexTypes']);
    Route::post('/leave/types', [HrLeaveController::class, 'storeType']);
    Route::patch('/leave/types/{id}', [HrLeaveController::class, 'updateType'])->whereNumber('id');
    Route::delete('/leave/types/{id}', [HrLeaveController::class, 'destroyType'])->whereNumber('id');
    Route::get('/leave/balances', [HrLeaveController::class, 'indexBalances']);
    Route::post('/leave/balances/ensure', [HrLeaveController::class, 'ensureBalance']);
    Route::get('/leave/requests', [HrLeaveController::class, 'indexRequests']);
    Route::post('/leave/requests', [HrLeaveController::class, 'storeRequest']);
    Route::post('/leave/requests/{id}/approve', [HrLeaveController::class, 'approve'])->whereNumber('id');
    Route::post('/leave/requests/{id}/reject', [HrLeaveController::class, 'reject'])->whereNumber('id');
    Route::post('/leave/requests/{id}/cancel', [HrLeaveController::class, 'cancel'])->whereNumber('id');

    // Payroll
    Route::get('/payroll/structures', [HrPayrollController::class, 'indexStructures']);
    Route::post('/payroll/structures', [HrPayrollController::class, 'storeStructure']);
    Route::patch('/payroll/structures/{id}', [HrPayrollController::class, 'updateStructure'])->whereNumber('id');
    Route::get('/payroll/compensations', [HrPayrollController::class, 'indexCompensations']);
    Route::post('/payroll/compensations', [HrPayrollController::class, 'storeCompensation']);
    Route::get('/payroll/pay-runs', [HrPayrollController::class, 'indexPayRuns']);
    Route::post('/payroll/pay-runs', [HrPayrollController::class, 'storePayRun']);
    Route::get('/payroll/pay-runs/{id}', [HrPayrollController::class, 'showPayRun'])->whereNumber('id');
    Route::post('/payroll/pay-runs/{id}/calculate', [HrPayrollController::class, 'calculatePayRun'])->whereNumber('id');
    Route::post('/payroll/pay-runs/{id}/approve', [HrPayrollController::class, 'approvePayRun'])->whereNumber('id');
    Route::post('/payroll/pay-runs/{id}/post', [HrPayrollController::class, 'postPayRun'])->whereNumber('id');

    // Talent
    Route::get('/talent/onboarding-templates', [HrTalentController::class, 'indexTemplates']);
    Route::post('/talent/onboarding-templates', [HrTalentController::class, 'storeTemplate']);
    Route::patch('/talent/onboarding-templates/{id}', [HrTalentController::class, 'updateTemplate'])->whereNumber('id');
    Route::post('/talent/onboarding/assign', [HrTalentController::class, 'assignOnboarding']);
    Route::get('/talent/onboarding-tasks', [HrTalentController::class, 'indexTasks']);
    Route::post('/talent/onboarding-tasks', [HrTalentController::class, 'storeTask']);
    Route::patch('/talent/onboarding-tasks/{id}', [HrTalentController::class, 'updateTask'])->whereNumber('id');
    Route::get('/talent/reviews', [HrTalentController::class, 'indexReviews']);
    Route::post('/talent/reviews', [HrTalentController::class, 'storeReview']);
    Route::patch('/talent/reviews/{id}', [HrTalentController::class, 'updateReview'])->whereNumber('id');

    // Reports & audit
    Route::get('/reports/paye-schedule', [HrReportController::class, 'payeSchedule']);
    Route::get('/reports/nssf-schedule', [HrReportController::class, 'nssfSchedule']);
    Route::get('/audit-logs', [HrReportController::class, 'auditLogs']);
});
