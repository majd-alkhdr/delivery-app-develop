{{--
    نموذج تحصيل المندوب: المبلغ، العملة (الدولار بسعر إلزامي)، رقم الإيصال الورقي (مطلوب)، صورة الإيصال (مطلوبة)، الملاحظة.
    زر الحفظ معطّل حتى يكتمل المطلوب. الدفعة والصورة تُرسلان معاً في طلب واحد.
--}}
<div class="modal fade" id="repPaymentModal" tabindex="-1" aria-labelledby="repPaymentTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content border-0">
            <form id="repPaymentForm" novalidate>
                <div class="modal-header text-bg-success">
                    <h3 class="modal-title fw-bold fs-6" id="repPaymentTitle"><i class="fas fa-hand-holding-dollar me-1"></i> تسجيل تحصيل</h3>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="customer_id">
                    <p class="fw-bold mb-1" id="rep_customer_name"></p>
                    <p class="small text-muted mb-3">الدَين المعروف: <span class="font-monospace fw-bold" dir="ltr" id="rep_debt"></span></p>

                    <div class="mb-3">
                        <label for="rep_amount" class="form-label small fw-bold text-secondary">المبلغ</label>
                        <input type="text" id="rep_amount" inputmode="decimal" dir="ltr" autocomplete="off" class="form-control form-control-lg border-2 text-center" required>
                    </div>
                    <div class="mb-3">
                        <div class="btn-group w-100" role="group" aria-label="العملة">
                            <input type="radio" class="btn-check" name="currency" id="rep_cur_syp" value="SYP" checked>
                            <label class="btn btn-outline-success fw-bold" for="rep_cur_syp">ليرة سورية</label>
                            <input type="radio" class="btn-check" name="currency" id="rep_cur_usd" value="USD">
                            <label class="btn btn-outline-success fw-bold" for="rep_cur_usd">دولار</label>
                        </div>
                    </div>
                    <div class="mb-3 d-none" id="rep_rate_box">
                        <label for="rep_rate" class="form-label small fw-bold text-secondary">سعر الصرف (إلزامي)</label>
                        <input type="text" id="rep_rate" inputmode="decimal" dir="ltr" autocomplete="off" class="form-control border-2 text-center">
                    </div>
                    <div class="mb-3">
                        <label for="rep_book_no" class="form-label small fw-bold text-secondary">رقم الإيصال الورقي (من الدفتر)</label>
                        <input type="text" id="rep_book_no" name="book_receipt_no" inputmode="numeric" dir="ltr" maxlength="40" autocomplete="off" class="form-control border-2 text-center" required>
                    </div>
                    <div class="mb-3">
                        <label for="rep_image" class="form-label small fw-bold text-secondary">صورة الإيصال (مطلوبة)</label>
                        <input type="file" id="rep_image" accept="image/*" capture="environment" class="form-control" required>
                        <img id="rep_image_preview" class="img-thumbnail mt-2 d-none" alt="معاينة صورة الإيصال" style="max-height: 180px">
                    </div>
                    <div class="mb-2">
                        <label for="rep_note" class="form-label small fw-bold text-secondary">ملاحظة</label>
                        <textarea name="note" id="rep_note" class="form-control border-2" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="alert alert-warning py-2 small d-none" id="rep_exceeds"></div>
                    <div class="small text-danger fw-bold" id="rep_missing" aria-live="polite"></div>
                </div>
                <div class="modal-footer d-grid">
                    <button type="submit" class="btn btn-success btn-lg fw-bold" id="rep_submit" disabled>
                        <i class="fas fa-floppy-disk"></i> حفظ التحصيل
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
