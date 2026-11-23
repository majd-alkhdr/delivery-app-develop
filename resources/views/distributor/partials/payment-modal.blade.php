{{-- نافذة تسجيل دفعة (مشتركة بين صفحة الكشف وتبويب العملاء). الحفظ محلياً أولاً ثم الإرسال --}}
<div class="modal fade" id="paymentModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content border-0">
            <div class="modal-header text-bg-success">
                <h3 class="modal-title fw-bold fs-6"><i class="fas fa-money-bill-wave me-1"></i> {{ $title ?? 'تسجيل دفعة' }}</h3>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form id="paymentForm" novalidate>
                <input type="hidden" name="customer_id">
                <div class="modal-body p-4 bg-light">
                    <div class="mb-3">
                        <span class="small fw-bold text-secondary d-block">الصيدلية</span>
                        <div class="form-control bg-white fw-bold" id="pay_customer_name"></div>
                    </div>
                    <div class="mb-3">
                        <span class="small fw-bold text-secondary d-block">الرصيد المستحق</span>
                        <div class="form-control bg-white fw-bold text-danger text-center font-monospace" dir="ltr" id="pay_due">—</div>
                    </div>
                    <div class="mb-3">
                        <label for="pay_amount" class="form-label small fw-bold text-secondary">المبلغ</label>
                        <input type="text" id="pay_amount" inputmode="numeric" dir="ltr" autocomplete="off" required
                            class="form-control form-control-lg border-2 fw-bold text-center fs-4">
                    </div>
                    @if ($canEnterUsd ?? false)
                        <div class="mb-3">
                            <span class="form-label small fw-bold text-secondary d-block">العملة</span>
                            <div class="btn-group w-100" role="group" aria-label="العملة">
                                <input type="radio" class="btn-check" name="currency" id="cur_syp" value="SYP" checked>
                                <label class="btn btn-outline-success fw-bold" for="cur_syp">ليرة سورية</label>
                                <input type="radio" class="btn-check" name="currency" id="cur_usd" value="USD">
                                <label class="btn btn-outline-success fw-bold" for="cur_usd">دولار</label>
                            </div>
                        </div>
                        <div class="mb-3 d-none" id="pay_rate_box">
                            <label for="pay_rate" class="form-label small fw-bold text-secondary">سعر الصرف (إلزامي)</label>
                            <input type="text" id="pay_rate" inputmode="decimal" dir="ltr" autocomplete="off" class="form-control border-2 text-center">
                            <div class="form-text">يُحسب ما يعادل المبلغ بالليرة بهذا السعر، ويُطبع على الإيصال.</div>
                        </div>
                    @else
                        <input type="radio" class="d-none" name="currency" value="SYP" checked>
                    @endif
                    <div class="mb-2">
                        <label for="pay_note" class="form-label small fw-bold text-secondary">ملاحظة</label>
                        <textarea name="note" id="pay_note" class="form-control border-2" rows="2" maxlength="500"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-white d-flex gap-2">
                    <button type="button" class="btn btn-light border fw-bold flex-grow-1" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success fw-bold flex-grow-1"><i class="fas fa-save"></i> حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>
