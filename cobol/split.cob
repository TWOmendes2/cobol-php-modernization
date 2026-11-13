       >>SOURCE FORMAT FREE
identification division.
program-id. split-sale.
environment division.
configuration section.
repository. function all intrinsic.
data division.
working-storage section.
01 input-value pic x(32).
01 total-cents pic 9(12) comp-3.
01 service-cents pic 9(12) comp-3.
01 product-cents pic 9(12) comp-3.
01 result-service pic 9(12).
01 result-product pic 9(12).
procedure division.
    accept input-value
    if function trim(input-value) = spaces
        display 'ERROR:EMPTY'
        move 1 to return-code
        goback
    end-if
    if function trim(input-value) is not numeric
        display 'ERROR:INVALID'
        move 1 to return-code
        goback
    end-if
    if function length(function trim(input-value)) > 12
        display 'ERROR:OVERFLOW'
        move 1 to return-code
        goback
    end-if
    move function numval(input-value) to total-cents
    compute service-cents rounded = total-cents * 0.70
    compute product-cents = total-cents - service-cents
    move service-cents to result-service
    move product-cents to result-product
    display result-service ' ' result-product
    move 0 to return-code
    goback.
