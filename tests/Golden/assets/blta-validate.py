# pyHanko B-LTA validation harness for phppdf.
#
# Usage: python blta-validate.py <pdf> <root-cert.pem-or-der>
#
# Builds the ValidationContext FROM the embedded DSS (hard-fail, no fetching) and
# asserts BOTH the signature AND the document timestamp validate to the trust
# root - i.e. the archive timestamp's TSA certificate is itself validatable from
# the embedded revocation alone (PAdES-B-LTA). Final line "OK" + exit 0 on
# success; anything else is a failure.

import sys
import traceback

from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.sign.validation import (
    validate_pdf_signature,
    validate_pdf_timestamp,
    DocumentSecurityStore,
)
from asn1crypto import x509, pem

pdf_path = sys.argv[1]
root_path = sys.argv[2]

data = open(root_path, "rb").read()
der = pem.unarmor(data)[2] if pem.detect(data) else data
root = x509.Certificate.load(der)

with open(pdf_path, "rb") as f:
    r = PdfFileReader(f)

    try:
        dss = DocumentSecurityStore.read_dss(r)
    except Exception:  # noqa: BLE001
        traceback.print_exc()
        print("NO_DSS")
        sys.exit(7)

    vc = dss.as_validation_context({
        "trust_roots": [root],
        "allow_fetching": False,
        "revocation_mode": "hard-fail",
    })

    sigs = r.embedded_signatures
    sig = None
    ts = None
    for s in sigs:
        if str(s.sig_object.get("/SubFilter")) == "/ETSI.RFC3161":
            ts = s
        else:
            sig = s
    if sig is None:
        print("NO_SIGNATURE")
        sys.exit(3)
    if ts is None:
        print("NO_DOCTIMESTAMP")
        sys.exit(3)

    try:
        st = validate_pdf_signature(sig, signer_validation_context=vc)
    except Exception:  # noqa: BLE001
        traceback.print_exc()
        print("SIG_RAISED")
        sys.exit(6)
    print("signature: intact=%s valid=%s trusted=%s" % (st.intact, st.valid, st.trusted))

    try:
        tst = validate_pdf_timestamp(ts, validation_context=vc)
    except Exception:  # noqa: BLE001
        traceback.print_exc()
        print("TS_RAISED")
        sys.exit(6)
    print("doctimestamp: intact=%s valid=%s trusted=%s" % (tst.intact, tst.valid, tst.trusted))

    if st.intact and st.valid and st.trusted and tst.intact and tst.valid and tst.trusted:
        print("OK")
        sys.exit(0)
    print("NOT_OK")
    sys.exit(4)
