{{--
    سؤال تثبيت الموقع المرجعي بعد تسجيل زيارة «مزار» أو دفعة لصيدلية بلا مرجع مؤكد.
    لا يحجب الحفظ: السجل محفوظ في الطابور المحلي قبل ظهور النافذة، والتثبيت طلب إضافي عليه.
    لا تُعرض الإحداثيات الخام، فقط المسافة التقريبية ورقم الدقة بالأمتار.
    الدقة تُراقَب باستمرار (watchPosition) والزر يتفعّل وحده عند بلوغ الحد المطلوب.
--}}
<div class="modal fade" id="pinModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="pinModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content border-0">
            <div class="modal-header text-bg-primary">
                <h3 class="modal-title fw-bold fs-6" id="pinModalTitle"><i class="fas fa-location-dot me-1"></i> موقع الصيدلية</h3>
            </div>
            <div class="modal-body p-4">
                <p class="fw-bold fs-6 mb-2">هذه الصيدلية بلا موقع مرجعي مؤكد. هل أنت الآن عند الصيدلية؟</p>
                <p class="small text-muted mb-2" id="pin_customer_name"></p>
                <div class="alert alert-warning py-2 small d-none" id="pin_distance_line"></div>
                <div class="alert alert-secondary py-2 small mb-2" id="pin_accuracy_live" aria-live="polite">
                    <i class="fas fa-satellite-dish me-1"></i>
                    <span id="pin_accuracy_text">جارٍ تحديد موقعك…</span>
                </div>
                <div class="alert alert-danger py-2 small d-none" id="pin_accuracy_line" role="alert">
                    <span id="pin_status_text">الموقع غير دقيق بعد، انتظر لحظات وأعد المحاولة.</span>
                    <button type="button" class="btn btn-sm btn-outline-dark ms-1 mt-1" id="pin_refresh_btn">إعادة المحاولة</button>
                </div>
            </div>
            <div class="modal-footer d-grid gap-2">
                <button type="button" class="btn btn-primary btn-lg fw-bold" id="pin_confirm_btn" disabled>
                    <i class="fas fa-thumbtack"></i> تثبيت موقعي كمرجع
                </button>
                <button type="button" class="btn btn-light border btn-lg fw-bold" id="pin_skip_btn">ليس الآن</button>
            </div>
        </div>
    </div>
</div>
