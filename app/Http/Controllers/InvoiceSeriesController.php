<?php

namespace App\Http\Controllers;

use App\Models\InvoiceSeries;
use Illuminate\Http\Request;

/**
 * شاشة بسيطة لتعديل أسماء سلاسل الفواتير (للمدير فقط). المفتاح لا يتغير.
 */
class InvoiceSeriesController extends Controller
{
    public function index()
    {
        $series = InvoiceSeries::orderBy('key')->get();

        return view('admin.series', compact('series'));
    }

    public function update(Request $request, InvoiceSeries $series)
    {
        $data = $request->validate([
            'label' => 'required|string|max:255',
        ], [
            'label.required' => 'الاسم مطلوب.',
        ]);

        $series->update(['label' => trim($data['label'])]);
        InvoiceSeries::flushCache();

        return back()->with('success', "تم تحديث اسم السلسلة \"{$series->key}\".");
    }
}
