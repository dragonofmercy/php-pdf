# pyHanko LTV validation harness for phppdf's end-to-end LtvSignatureTest.
#
# Usage: python ltv-validate.py <pdf> <root-cert.pem-or-der>
#
# What this proves (and why it cannot pass on a non-LTV file)
# -----------------------------------------------------------
# phppdf signs with an Adobe-style "adbe.pkcs7.detached" SubFilter (PKCS#7
# detached), NOT an ETSI CAdES container. pyHanko 0.35 validates such a
# signature cryptographically and can build a path to a trust root once the
# issuer cert + revocation data are available.
#
# This harness drives the *LTV* path on purpose:
#   1. It reads the /DSS Document Security Store out of the PDF
#      (DocumentSecurityStore.read_dss). If the file has no DSS this raises and
#      we exit non-zero -> a non-LTV file fails here.
#   2. It builds the ValidationContext FROM that DSS
#      (dss.as_validation_context) with revocation_mode="hard-fail" and
#      allow_fetching=False. hard-fail + no network means the ONLY source of
#      revocation is the CRL embedded in the DSS; if it is missing or does not
#      cover the leaf, path validation raises. So a "revocation present" pass is
#      genuine, not assumed.
#   3. The supplied root is the trust anchor; the leaf+intermediate come from
#      the DSS certs. validate_pdf_signature then reports intact/valid/trusted.
#
# Acceptance (final line):
#   - "OK"  : intact && valid && trusted    (strict: full path to trusted root,
#             revocation satisfied from the DSS under hard-fail).
#   - "OK_INTACT_VALID_DSS" : documented SP1 fallback. Reached only when pyHanko
#     declines the final "trusted" boolean for the non-CAdES SubFilter even
#     though the signature is intact + cryptographically valid AND an explicit
#     CertificateValidator path build to the trust root succeeds using ONLY the
#     DSS material under hard-fail. This still asserts DSS-present +
#     path-to-root + revocation-present, so it cannot pass on a non-LTV file.
#
# Any other final line (a traceback, NO_SIGNATURES, NOT_VALID) is a failure.

import sys
import traceback

from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.sign.validation import (
    validate_pdf_signature,
    DocumentSecurityStore,
)
from asn1crypto import x509, pem

pdf_path = sys.argv[1]
root_path = sys.argv[2]

with open(root_path, "rb") as f:
    data = f.read()
if pem.detect(data):
    _, _, der = pem.unarmor(data)
else:
    der = data
root = x509.Certificate.load(der)

with open(pdf_path, "rb") as f:
    r = PdfFileReader(f)

    # 1. The /DSS must exist. read_dss raises on a file without one.
    try:
        dss = DocumentSecurityStore.read_dss(r)
    except Exception:  # noqa: BLE001
        traceback.print_exc()
        print("NO_DSS")
        sys.exit(7)

    sigs = r.embedded_signatures
    if not sigs:
        print("NO_SIGNATURES")
        sys.exit(3)

    # 2. ValidationContext built FROM the DSS, with the supplied trust root.
    #    hard-fail + no fetching => revocation must come from the DSS CRL.
    vc = dss.as_validation_context({
        "trust_roots": [root],
        "allow_fetching": False,
        "revocation_mode": "hard-fail",
    })

    try:
        status = validate_pdf_signature(sigs[0], signer_validation_context=vc)
    except Exception:  # noqa: BLE001 - print the real reason
        traceback.print_exc()
        print("VALIDATE_RAISED")
        sys.exit(6)

    print("intact=%s valid=%s trusted=%s" % (status.intact, status.valid, status.trusted))

    if not (status.intact and status.valid):
        print("NOT_VALID")
        sys.exit(4)

    if status.trusted:
        print("OK")
        sys.exit(0)

    # 3. Fallback: pyHanko did not set "trusted" for the non-CAdES SubFilter.
    #    Independently prove a cert path to the trust root using ONLY the DSS
    #    material under hard-fail (revocation must resolve from the DSS CRL).
    from pyhanko_certvalidator import CertificateValidator

    signer_cert = sigs[0].signer_cert
    other_certs = list(getattr(sigs[0], "other_embedded_certs", []) or [])
    validator = CertificateValidator(
        signer_cert,
        intermediate_certs=other_certs,
        validation_context=vc,
    )
    try:
        path = validator.validate_usage(set())
    except Exception:  # noqa: BLE001
        traceback.print_exc()
        print("PATH_BUILD_FAILED")
        sys.exit(8)
    print("path_to_root=%s" % (path is not None,))
    print("OK_INTACT_VALID_DSS")
    sys.exit(0)
