<?php
/*
 * The MIT License
 *
 * Copyright 2020 João Rebelo.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
declare(strict_types=1);

namespace Rebelo\SaftPt\Validate;

use Rebelo\SaftPt\AuditFile\AAuditFile;
use Rebelo\SaftPt\AuditFile\AuditFile;
use Rebelo\SaftPt\Sign\Sign;
use Rebelo\Decimal\UDecimal;
use Rebelo\Decimal\Decimal;
use Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\StockMovement;
use Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\Line;
use Rebelo\SaftPt\AuditFile\MasterFiles\TaxType;
use Rebelo\SaftPt\AuditFile\MasterFiles\TaxCode;
use Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\MovementStatus;
use Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\MovementOfGoods as SaftMovementOfGoods;
use Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\DocumentStatus;
use Rebelo\SaftPt\AuditFile\SourceDocuments\OrderReferences;
use Rebelo\SaftPt\AuditFile\SourceDocuments\Tax;
use Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\DocumentTotals;
use Rebelo\SaftPt\AuditFile\SourceDocuments\SourceBilling;
use Rebelo\SaftPt\AuditFile\SourceDocuments\References;
use Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\MovementType;

/**
 * Validate MovementOfGoods table.<br>
 * This class will validate the values of MovementOfGoods, the
 * signtuare hash and dates
 *
 * @author João Rebelo
 * @since 1.0.0
 */
class MovementOfGoods extends ADocuments
{
    /**
     * The calculated number of movement lines
     * @var int
     */
    protected int $numberOfMovementLines = 0;

    /**
     * The calculated total quantity issued
     * @var \Rebelo\Decimal\UDecimal
     */
    protected UDecimal $totalQuantityIssued;

    /**
     * Validate MovementOfGoods table.<br>
     * This class will validate the values of MovementOfGoods, the
     * signtuare hash and dates
     * @param \Rebelo\SaftPt\AuditFile\AuditFile $auditFile The AuditFile to be validated
     * @param \Rebelo\SaftPt\Sign\Sign $sign The sign class to be used to validate the hash, must have the public key defined
     * @since 1.0.0
     */
    public function __construct(AuditFile $auditFile, Sign $sign)
    {
        \Logger::getLogger(\get_class($this))->debug(__METHOD__);
        parent::__construct($auditFile, $sign);
        $this->totalQuantityIssued = new UDecimal(
            0.0,
            ADocuments::CALC_PRECISION
        );
        $sourceDoc                 = $auditFile->getSourceDocuments(false);
        if ($sourceDoc !== null) {
            $movementOfGoods = $sourceDoc->getMovementOfGoods(false);
            if ($movementOfGoods !== null) {
                $movementOfGoods->setMovOfGoodsTableTotalCalc(
                    new \Rebelo\SaftPt\Validate\MovOfGoodsTableTotalCalc()
                );
            }
        }
    }

    /**
     * Validate the workingdocuments
     * @return bool
     * @since 1.0.0
     */
    public function validate(): bool
    {
        \Logger::getLogger(\get_class($this))->debug(__METHOD__);
        try {
            $movementOfGoods = $this->auditFile->getSourceDocuments()
                ->getMovementOfGoods(false);

            if ($movementOfGoods === null) {
                \Logger::getLogger(\get_class($this))
                    ->debug(__METHOD__." no movement of goods documents to be vaidated");
                return $this->isValid;
            }

            $movementOfGoods->setMovOfGoodsTableTotalCalc(new MovOfGoodsTableTotalCalc());

            $this->numberOfLinesAndTotalQuantity();

            if ($movementOfGoods->getNumberOfMovementLines() === 0) {

                if ($movementOfGoods->getNumberOfMovementLines() !== 0.0) {
                    $msg           = \sprintf(
                        AAuditFile::getI18n()->get("mv_goods_num_lines_should_be_zero"),
                        $movementOfGoods->getNumberOfMovementLines()
                    );
                    $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                    $movementOfGoods->addError(
                        $msg, SaftMovementOfGoods::N_NUMBEROFMOVEMENTLINES
                    );
                    $this->isValid = false;
                }

                if ($movementOfGoods->getTotalQuantityIssued() !== 0.0) {
                    $msg           = \sprintf(
                        AAuditFile::getI18n()->get(
                            "mv_goods_total_qt_should_be_zero"
                        ), $movementOfGoods->getTotalQuantityIssued()
                    );
                    $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                    $movementOfGoods->addError(
                        $msg, SaftMovementOfGoods::N_TOTALQUANTITYISSUED
                    );
                    $this->isValid = false;
                }

                return $this->isValid;
            }

            $order = $movementOfGoods->getOrder();

            foreach (\array_keys($order) as $type) {
                foreach (\array_keys($order[$type]) as $serie) {
                    foreach (\array_keys($order[$type][$serie]) as $no) {
                        /* @var $stockMovDocument \Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\StockMovement */
                        $stockMovDocument = $order[$type][$serie][$no];
                        if ((string) $type !== $this->lastType || (string) $serie
                            !== $this->lastSerie) {
                            $this->lastHash            = "";
                            $this->lastDocDate         = null;
                            $this->lastSystemEntryDate = null;
                        }
                        $stockMovDocument->setDocTotalcal(new DocTotalCalc());
                        $this->stockMovement($stockMovDocument);
                        $this->lastType  = (string) $type;
                        $this->lastSerie = (string) $serie;
                    }
                }
            }
        } catch (\Exception | \Error $e) {
            $this->isValid = false;

            $this->auditFile->getErrorRegistor()
                ->addExceptionErrors($e->getMessage());

            \Logger::getLogger(\get_class($this))
                ->debug(
                    \sprintf(
                        __METHOD__." validate error '%s'", $e->getMessage()
                    )
                );
        }
        return $this->isValid;
    }

    /**
     * Validate StockMovement
     * @param \Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\StockMovement $stockMovDocument
     * @return void
     * @since 1.0.0
     */
    protected function stockMovement(StockMovement $stockMovDocument): void
    {
        \Logger::getLogger(\get_class($this))->debug(__METHOD__);
        try {
            $this->docCredit  = new UDecimal(0.0, static::CALC_PRECISION);
            $this->docDebit   = new UDecimal(0.0, static::CALC_PRECISION);
            $this->netTotal   = new UDecimal(0.0, static::CALC_PRECISION);
            $this->taxPayable = new UDecimal(0.0, static::CALC_PRECISION);
            $this->grossTotal = new UDecimal(0.0, static::CALC_PRECISION);

            if ($stockMovDocument->issetDocumentNumber() === false) {
                $msg           = AAuditFile::getI18n()->get("workdoc_number_not_defined");
                $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                $stockMovDocument->addError($msg);
                $this->isValid = false;
                return;
            }

            if ($stockMovDocument->issetMovementType() === false) {
                $msg           = \sprintf(
                    AAuditFile::getI18n()->get(
                        "workdoctype_not_defined"
                    ), $stockMovDocument->getDocumentNumber()
                );
                $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                $stockMovDocument->addError($msg, StockMovement::N_MOVEMENTTYPE);
                \Logger::getLogger(\get_class($this))->info($msg);
                $this->isValid = false;
                return;
            }

            if ($stockMovDocument->issetMovementDate() === false) {
                $msg           = \sprintf(
                    AAuditFile::getI18n()->get(
                        "document_date_not_defined"
                    ), $stockMovDocument->getDocumentNumber()
                );
                $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                $stockMovDocument->addError($msg, StockMovement::N_MOVEMENTDATE);
                \Logger::getLogger(\get_class($this))->info($msg);
                $this->isValid = false;
                return;
            }

            if ($stockMovDocument->issetSystemEntryDate() === false) {
                $msg           = \sprintf(
                    AAuditFile::getI18n()->get(
                        "document_systementrydate_not_defined"
                    ), $stockMovDocument->getDocumentNumber()
                );
                $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                $stockMovDocument->addError(
                    $msg,
                    StockMovement::N_SYSTEMENTRYDATE
                );
                \Logger::getLogger(\get_class($this))->info($msg);
                $this->isValid = false;
                return;
            }

            $this->sign($stockMovDocument);
            $this->stockMovementDateAndSystemEntryDate($stockMovDocument);
            $this->movementStartAndEndTime($stockMovDocument);
            $this->customerIdOrSupplierId($stockMovDocument);
            $this->documentStatus($stockMovDocument);
            $this->lines($stockMovDocument);
            $this->totals($stockMovDocument);
        } catch (\Exception | \Error $e) {
            $this->auditFile->getErrorRegistor()
                ->addExceptionErrors($e->getMessage());
            \Logger::getLogger(\get_class($this))
                ->debug(
                    \sprintf(
                        __METHOD__." validate error '%s'", $e->getMessage()
                    )
                );
            $stockMovDocument->addError($e->getMessage());
            $this->isValid = false;
        }
    }

    /**
     * Validate if the NumberOfEntries is equal to the number of StockMovements
     * @return void
     * @since 1.0.0
     */
    protected function numberOfLinesAndTotalQuantity(): void
    {
        \Logger::getLogger(\get_class($this))->debug(__METHOD__);
        $movementOfGoods = $this->auditFile->getSourceDocuments()->getMovementOfGoods();

        $testNlines = $this->numberOfMovementLines === $movementOfGoods->getNumberOfMovementLines();
        $testQt     = $this->totalQuantityIssued->equals(
            $movementOfGoods->getTotalQuantityIssued()
        );

        $this->auditFile->getSourceDocuments()->getMovementOfGoods()
            ->getMovOfGoodsTableTotalCalc()->setNumberOfMovementLines(
                $this->numberOfMovementLines
            );

        $this->auditFile->getSourceDocuments()->getMovementOfGoods()
            ->getMovOfGoodsTableTotalCalc()->setTotalQuantityIssued(
                $this->totalQuantityIssued->valueOf()
            );

        if ($testNlines === false) {
            $msg           = \sprintf(
                AAuditFile::getI18n()->get(
                    "wrong_number_of_movement_lines"
                ), $movementOfGoods->getNumberOfMovementLines(),
                $this->numberOfMovementLines
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $movementOfGoods->addError(
                $msg, SaftMovementOfGoods::N_NUMBEROFMOVEMENTLINES
            );
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
        }

        if ($testQt === false) {
            $msg           = \sprintf(
                AAuditFile::getI18n()->get(
                    "wrong_number_of_total_qt_issued"
                ), $movementOfGoods->getTotalQuantityIssued(),
                $this->totalQuantityIssued
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $movementOfGoods->addError(
                $msg, SaftMovementOfGoods::N_TOTALQUANTITYISSUED
            );
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
        }
    }

    /**
     * Validate the Document Status
     * @param \Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\StockMovement $stockMovDocument
     * @return void
     * @since 1.0.0
     */
    protected function documentStatus(StockMovement $stockMovDocument): void
    {
        if ($stockMovDocument->issetDocumentStatus() === false) {
            $msg           = \sprintf(
                AAuditFile::getI18n()->get(
                    "document_status_not_defined"
                ), $stockMovDocument->getDocumentNumber()
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $stockMovDocument->addError($msg, DocumentStatus::N_DOCUMENTSTATUS);
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
            return;
        }

        $status = $stockMovDocument->getDocumentStatus();

        if ($status->getMovementStatusDate()->isEarlier($stockMovDocument->getMovementDate())) {
            $msg           = \sprintf(
                AAuditFile::getI18n()->get(
                    "document_status_date_earlier"
                ), $stockMovDocument->getDocumentNumber()
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $stockMovDocument->addError(
                $msg,
                DocumentStatus::N_MOVEMENTSTATUSDATE
            );
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
            return;
        }

        if ($status->getMovementStatus()->isEqual(MovementStatus::A) &&
            $status->getReason() === null) {

            $msg           = \sprintf(
                AAuditFile::getI18n()->get(
                    "document_status_cancel_no_reason"
                ), $stockMovDocument->getDocumentNumber()
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $stockMovDocument->addError($msg, DocumentStatus::N_REASON);
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
            return;
        }
    }

    /**
     * validate if the customerID or SupplierID of the StockMovement if is setted and if exits in
     * the customer table
     * @param \Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\StockMovement $stockMovDocument
     * @return void
     * @since 1.0.0
     */
    protected function customerIdOrSupplierId(StockMovement $stockMovDocument): void
    {
        \Logger::getLogger(\get_class($this))->debug(__METHOD__);
        if ($stockMovDocument->getMovementType()->isEqual(MovementType::GD)) {
            if ($stockMovDocument->issetSupplierID()) {
                $allSupplier = $this->auditFile->getMasterFiles()->getAllSupplierID();
                if (\in_array($stockMovDocument->getSupplierID(), $allSupplier) === false) {

                    $msg = \sprintf(
                        AAuditFile::getI18n()->get("supplierID_not_exits"),
                        $stockMovDocument->getSupplierID(),
                        $stockMovDocument->getDocumentNumber()
                    );

                    $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                    $stockMovDocument->addError(
                        $msg,
                        StockMovement::N_CUSTOMERID
                    );
                    \Logger::getLogger(\get_class($this))->info($msg);
                    $this->isValid = false;
                }
                return;
            }
        } else {
            if ($stockMovDocument->issetCustomerID()) {
                $allCustomer = $this->auditFile->getMasterFiles()->getAllCustomerID();
                if (\in_array($stockMovDocument->getCustomerID(), $allCustomer) === false) {

                    $msg = \sprintf(
                        AAuditFile::getI18n()->get("customerID_not_exits"),
                        $stockMovDocument->getCustomerID(),
                        $stockMovDocument->getDocumentNumber()
                    );

                    $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                    $stockMovDocument->addError(
                        $msg,
                        StockMovement::N_CUSTOMERID
                    );
                    \Logger::getLogger(\get_class($this))->info($msg);
                    $this->isValid = false;
                }
                return;
            }
        }

        $msg           = \sprintf(
            AAuditFile::getI18n()->get("customerID_not_defined_in_document"),
            $stockMovDocument->getDocumentNumber()
        );
        $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
        $stockMovDocument->addError($msg, StockMovement::N_CUSTOMERID);
        \Logger::getLogger(\get_class($this))->info($msg);
        $this->isValid = false;
    }

    /**
     * validate each line of the StockMovement
     * @param \Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\StockMovement $stockMovDocument
     * @return void
     * @since 1.0.0
     */
    protected function lines(StockMovement $stockMovDocument): void
    {
        \Logger::getLogger(\get_class($this))->debug(__METHOD__);
        if (\count($stockMovDocument->getLine()) === 0) {
            $msg           = \sprintf(
                AAuditFile::getI18n()->get("document_without_lines"),
                $stockMovDocument->getDocumentNumber()
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $stockMovDocument->addError($msg, StockMovement::N_DOCUMENTNUMBER);
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
            return;
        }

        $n           = 0;
        /* @var $lineNoStack int[] */
        $lineNoStack = array();
        $lineNoError = false;
        //$hasDebit and $hasCredit is to check if the document as both debit and credit lines
        $hasDebit    = false;
        $hasCredit   = false;

        // For the case that line anulation are use,
        // validate if the anulation is bigger or not

        /* @var $anulaDebitValue \Rebelo\Decimal\UDecimal[] */
        $anulaDebitValue  = array();
        /* @var $anulaCreditValue \Rebelo\Decimal\UDecimal[] */
        $anulaCreditValue = array();
        /* @var $anulaDebitQt \Rebelo\Decimal\UDecimal[] */
        $anulaDebitQt     = array();
        /* @var $anulaCreditQt \Rebelo\Decimal\UDecimal[] */
        $anulaCreditQt    = array();

        foreach ($stockMovDocument->getLine() as $line) {
            /* @var $line \Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\Line */
            if ($lineNoError === false) {
                if ($line->issetLineNumber()) {
                    if ($this->getContinuesLines() && $line->getLineNumber() !== ++$n) {
                        $msg           = \sprintf(
                            AAuditFile::getI18n()->get("document_line_no_continues"),
                            $stockMovDocument->getDocumentNumber()
                        );
                        $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                        $line->addError($msg, Line::N_LINENUMBER);
                        \Logger::getLogger(\get_class($this))->info($msg);
                        $this->isValid = false;
                        $lineNoError   = true;
                    } elseif (\in_array($line->getLineNumber(), $lineNoStack)) {
                        $msg           = \sprintf(
                            AAuditFile::getI18n()->get("document_line_duplicated"),
                            $stockMovDocument->getDocumentNumber()
                        );
                        $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                        $line->addError($msg, Line::N_LINENUMBER);
                        \Logger::getLogger(\get_class($this))->info($msg);
                        $this->isValid = false;
                        $lineNoError   = true;
                    }
                    $lineNoStack[] = $line->getLineNumber();
                } else {
                    $msg           = \sprintf(
                        AAuditFile::getI18n()->get("document_line_no_number"),
                        $stockMovDocument->getDocumentNumber()
                    );
                    $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                    $line->addError($msg, Line::N_LINENUMBER);
                    \Logger::getLogger(\get_class($this))->info($msg);
                    $this->isValid = false;
                    $lineNoError   = true;
                    continue;
                }
            }

            if ($line->issetQuantity() === false) {
                $msg           = \sprintf(
                    AAuditFile::getI18n()->get("document_line_no_quantity"),
                    $stockMovDocument->getDocumentNumber()
                );
                $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                $line->addError($msg, Line::N_QUANTITY);
                \Logger::getLogger(\get_class($this))->info($msg);
                $this->isValid = false;
                continue;
            }

            if ($line->issetUnitPrice() === false) {
                $msg           = \sprintf(
                    AAuditFile::getI18n()->get("document_line_no_unit_price"),
                    $stockMovDocument->getDocumentNumber()
                );
                $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                $line->addError($msg, Line::N_UNITPRICE);
                \Logger::getLogger(\get_class($this))->info($msg);
                $this->isValid = false;
                continue;
            }

            $lineValue  = new Decimal(0.0, static::CALC_PRECISION);
            $lineTaxCal = new UDecimal(0.0, static::CALC_PRECISION);

            if ($line->getCreditAmount() === null &&
                $line->getDebitAmount() === null) {

                $msg           = \sprintf(
                    AAuditFile::getI18n()->get("document_no_debit_or_credit"),
                    $stockMovDocument->getDocumentNumber(),
                    $line->getLineNumber()
                );
                $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                $line->addError($msg);
                \Logger::getLogger(\get_class($this))->info($msg);
                $this->isValid = false;
                continue;
            }

            $lineAmount = $line->getCreditAmount() === null ?
                $line->getDebitAmount() * -1.0 :
                $line->getCreditAmount();

            // Get value for total validation
            $lineValue->plusThis($lineAmount);

            if ($line->getTax(false) !== null) {
                $lineTax = $line->getTax();
                if ($lineTax->getTaxPercentage() !== null &&
                    $lineTax->getTaxPercentage() !== 0.0) {

                    $lineFactor = $lineTax->getTaxPercentage() / 100;

                    $lineTaxCal = new UDecimal(
                        $lineFactor * \abs($lineAmount), static::CALC_PRECISION
                    );
                } else {
                    $msg           = \sprintf(
                        AAuditFile::getI18n()->get("document_line_no_tax_defined"),
                        $stockMovDocument->getDocumentNumber(),
                        $line->getLineNumber()
                    );
                    $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                    $line->addError($msg);
                    \Logger::getLogger(\get_class($this))->info($msg);
                    $this->isValid = false;
                    continue;
                }
            }

            // validate unit price and quantuty
            $unitPrice = new UDecimal(
                $line->getUnitPrice(), static::CALC_PRECISION
            );

            $uniQt = $unitPrice->multiply(
                new UDecimal($line->getQuantity(), static::CALC_PRECISION)
            );

            $stockMovDocument->getDocTotalcal()->addLineTotal(
                $line->getLineNumber(), $uniQt->valueOf()
            );

            if ($uniQt->signedSubtract($lineValue->abs())->abs()->valueOf() > $this->getDeltaLine()) {
                $msg           = \sprintf(
                    AAuditFile::getI18n()->get("document_line_value_not_quantity_price"),
                    $stockMovDocument->getDocumentNumber(),
                    $line->getLineNumber()
                );
                $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                $stockMovDocument->addError(
                    $msg,
                    $line->getCreditAmount() === null ?
                        Line::N_DEBITAMOUNT : Line::N_CREDITAMOUNT
                );
                \Logger::getLogger(\get_class($this))->info($msg);
                $this->isValid = false;
            }

            $docStat = $stockMovDocument->getDocumentStatus()->getMovementStatus();

            if($docStat->isNotEqual(MovementStatus::A)){
                $this->totalQuantityIssued->plusThis($line->getQuantity());
            }
            $this->numberOfMovementLines++;
            
            if ($line->getCreditAmount() !== null) {
                $credit = new UDecimal(
                    $line->getCreditAmount(), static::CALC_PRECISION
                );
                $this->docCredit->plusThis($credit);

                $hasCredit = true;
            }

            if ($line->getDebitAmount() !== null) {
                $debit = new UDecimal(
                    $line->getDebitAmount(), static::CALC_PRECISION
                );
                $this->docDebit->plusThis($debit);
                $hasDebit = true;
            }

            if ($hasCredit && $hasDebit) {
                $msg           = \sprintf(
                    AAuditFile::getI18n()->get("document_has_credit_and_debit_lines"),
                    $stockMovDocument->getDocumentNumber()
                );
                $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                $stockMovDocument->addError($msg);
                \Logger::getLogger(\get_class($this))->info($msg);
                $this->isValid = false;
                return;
            }

            $this->producCode($line, $stockMovDocument);

            if ($line->getTax(false)) {
                $this->tax($line, $stockMovDocument);
            } else {
                $msg           = \sprintf(
                    AAuditFile::getI18n()->get("tax_must_be_defined"),
                    $stockMovDocument->getDocumentNumber(),
                    $line->getLineNumber()
                );
                $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                $line->addError($msg, Line::N_TAX);
                \Logger::getLogger(\get_class($this))->info($msg);
                $this->isValid = false;
                return;
            }
            $this->netTotal->plusThis($lineValue->abs());

            $this->taxPayable->plusThis($lineTaxCal);

            if (\count($line->getOrderReferences()) > 0) {
                $this->orderReferences($line, $stockMovDocument);
            }
        }

        $this->grossTotal = $this->netTotal->plus($this->taxPayable);
    }

    /**
     * Validate the Order References
     * @param \Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\Line $line
     * @param \Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\StockMovement $stockMovDocument
     * @return void
     * @since 1.0.0
     */
    public function orderReferences(Line $line, StockMovement $stockMovDocument): void
    {
        \Logger::getLogger(\get_class($this))->debug(__METHOD__);
        foreach ($line->getOrderReferences() as $orderRef) {
            /* @var $orderRef \Rebelo\SaftPt\AuditFile\SourceDocuments\OrderReferences */
            if ($orderRef->getOriginatingON() === null) {
                $msg           = \sprintf(
                    AAuditFile::getI18n()->get(
                        "order_reference_document_not_incicated"
                    ), $stockMovDocument->getDocumentNumber(),
                    $line->getLineNumber()
                );
                $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                $orderRef->addError($msg, OrderReferences::N_ORIGINATINGON);
                \Logger::getLogger(\get_class($this))->info($msg);
                $this->isValid = false;
            } else {
                $val = AAuditFile::validateDocNumber($orderRef->getOriginatingON());
                if ($val === false) {
                    $msg = \sprintf(
                        AAuditFile::getI18n()->get(
                            "order_reference_document_number_not_valid"
                        ), $stockMovDocument->getDocumentNumber(),
                        $line->getLineNumber()
                    );
                    $this->auditFile->getErrorRegistor()->addWarning($msg);
                    $orderRef->addWarning($msg);
                    \Logger::getLogger(\get_class($this))->info($msg);
                }
            }

            if ($orderRef->getOrderDate() === null) {
                $docStatus = $stockMovDocument->getDocumentStatus()->getMovementStatus();
                if ($docStatus->isNotEqual(MovementStatus::A)) {
                    $msg           = \sprintf(
                        AAuditFile::getI18n()->get(
                            "order_reference_date_not_incicated"
                        ), $stockMovDocument->getDocumentNumber(),
                        $line->getLineNumber()
                    );
                    $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                    $orderRef->addError($msg, OrderReferences::N_ORDERDATE);
                    \Logger::getLogger(\get_class($this))->info($msg);
                    $this->isValid = false;
                }
            } elseif ($orderRef->getOrderDate()->isLater($stockMovDocument->getMovementDate())) {

                $msg = \sprintf(
                    AAuditFile::getI18n()->get("order_reference_date_later"),
                    $stockMovDocument->getDocumentNumber(),
                    $line->getLineNumber()
                );

                $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                $orderRef->addError($msg, OrderReferences::N_ORDERDATE);
                \Logger::getLogger(\get_class($this))->info($msg);
                $this->isValid = false;
            }
        }
    }

    /**
     * Validate if Product CodeExist
     * @param \Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\Line $line
     * @param \Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\StockMovement $stockMovDocument
     * @return void
     * @since 1.0.0
     */
    protected function producCode(Line $line, StockMovement $stockMovDocument): void
    {
        \Logger::getLogger(\get_class($this))->debug(__METHOD__);
        if ($line->issetProductCode()) {
            if (\in_array(
                $line->getProductCode(),
                $this->auditFile->getMasterFiles()->getAllProductCode()
            ) === false
            ) {

                $msg = \sprintf(
                    AAuditFile::getI18n()->get("document_line_product_code_not_exist"),
                    $stockMovDocument->getDocumentNumber(),
                    $line->getLineNumber(), $line->getProductCode()
                );

                $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                $line->addError($msg, Line::N_PRODUCTCODE);
                \Logger::getLogger(\get_class($this))->info($msg);
                $this->isValid = false;
            }
        } else {

            $msg = \sprintf(
                AAuditFile::getI18n()->get("document_line_product_code_not_defined"),
                $stockMovDocument->getDocumentNumber(), $line->getLineNumber()
            );

            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $line->addError($msg, Line::N_PRODUCTCODE);
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
        }
    }

    /**
     * Validate the line Tax
     * @param \Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\Line $line
     * @param \Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\StockMovement $stockMovDocument
     * @return void
     * @since 1.0.0
     */
    protected function tax(Line $line, StockMovement $stockMovDocument): void
    {
        \Logger::getLogger(\get_class($this))->debug(__METHOD__);

        $lineTax = $line->getTax(false);

        if ($lineTax === null) {
            $msg           = \sprintf(
                AAuditFile::getI18n()->get("tax_must_be_defined"),
                $stockMovDocument->getDocumentNumber(), $line->getLineNumber()
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $line->addError($msg, Line::N_TAX);
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
            return;
        }

        if ($lineTax->issetTaxType() === false) {
            $msg           = \sprintf(
                AAuditFile::getI18n()->get("tax_must_have_type"),
                $stockMovDocument->getDocumentNumber(), $line->getLineNumber()
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $lineTax->addError($msg, Tax::N_TAXTYPE);
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
            return;
        }

        if ($lineTax->issetTaxCode() === false) {
            $msg           = \sprintf(
                AAuditFile::getI18n()->get("tax_must_have_code"),
                $stockMovDocument->getDocumentNumber(), $line->getLineNumber()
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $lineTax->addError($msg, Tax::N_TAXCODE);
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
            return;
        }

        if ($lineTax->issetTaxCountryRegion() === false) {
            $msg           = \sprintf(
                AAuditFile::getI18n()->get("tax_must_have_region"),
                $stockMovDocument->getDocumentNumber(), $line->getLineNumber()
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $lineTax->addError($msg, Tax::N_TAXCOUNTRYREGION);
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
            return;
        }


        if ($lineTax->issetTaxPercentage() === false) {

            $msg = \sprintf(
                AAuditFile::getI18n()->get("tax_iva_must_have_percentage"),
                $stockMovDocument->getDocumentNumber()
            );

            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $lineTax->addError($msg, Tax::N_TAXPERCENTAGE);
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
            return;
        }

        if ($lineTax->getTaxPercentage() === 0.0) {
            if ($line->getTaxExemptionCode() === null ||
                $line->getTaxExemptionReason() === null) {

                $msg = \sprintf(
                    AAuditFile:: getI18n()->get("tax_zero_must_have_code_and_reason"),
                    $stockMovDocument->getDocumentNumber()
                );

                $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                $line->addError($msg, Line::N_TAXEXEMPTIONCODE);
                $line->addError($msg, Line::N_TAXEXEMPTIONREASON);
                \Logger::getLogger(\get_class($this))->info($msg);
                $this->isValid = false;
            }
        }

        if ($lineTax->getTaxCode()->isEqual(TaxCode::ISE)) {
            if ($line->getTaxExemptionCode() === null ||
                $line->getTaxExemptionReason() === null) {

                $msg = \sprintf(
                    AAuditFile::getI18n()->get("tax_iva_code_ise_must_have_code_and_reason"),
                    $stockMovDocument->getDocumentNumber()
                );

                $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
                $line->addError($msg, Line::N_TAXEXEMPTIONCODE);
                $line->addError($msg, Line::N_TAXEXEMPTIONREASON);
                \Logger::getLogger(\get_class($this))->info($msg);
                $this->isValid = false;
            }
        }


        if ($lineTax->getTaxCode() !== TaxCode::ISE &&
            $lineTax->getTaxPercentage() !== 0.0 &&
            ($line->getTaxExemptionCode() !== null ||
            $line->getTaxExemptionReason() !== null)
        ) {

            $msg           = \sprintf(
                AAuditFile::getI18n()->get("tax_iva_exception_code_or_reason_only_isent"),
                $stockMovDocument->getDocumentNumber()
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $line->addError($msg, Line::N_TAXEXEMPTIONCODE);
            $line->addError($msg, Line::N_TAXEXEMPTIONREASON);
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
        }

        // valiedate if exists in tax table
        foreach ($this->auditFile->getMasterFiles()->getTaxTableEntry() as $taxEntry) {
            /* @var $taxEntry \Rebelo\SaftPt\AuditFile\MasterFiles\TaxTableEntry */
            if ($taxEntry->issetTaxType() === false ||
                $taxEntry->issetTaxCode() === false ||
                $taxEntry->issetTaxCountryRegion() === false
            ) {
                continue;
            }

            if ($taxEntry->getTaxType()->isNotEqual($lineTax->getTaxType()) ||
                $taxEntry->getTaxPercentage() !== $lineTax->getTaxPercentage() ||
                $taxEntry->getTaxCountryRegion()->isNotEqual($lineTax->getTaxCountryRegion())) {
                continue;
            }

            if ($taxEntry->getTaxExpirationDate() === null) {// is valid
                return;
            }
            if ($taxEntry->getTaxExpirationDate()->isLater($stockMovDocument->getMovementDate())) {// is valid
                return;
            }
        }

        $this->isValid = false; // No table tax entry
        $msg           = \sprintf(
            AAuditFile::getI18n()->get("no_tax_entry_for_line_document"),
            $line->getLineNumber(), $stockMovDocument->getDocumentNumber()
        );
        $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
        \Logger::getLogger(\get_class($this))->info($msg);
        $line->addError($msg);
        $this->isValid = false;
    }

    /**
     * Validate the document total, only can be invoked after
     * validate lines (Because total controls are getted from that validation)
     * @param \Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\StockMovement $stockMovDocument
     * @return void
     * @since 1.0.0
     */
    protected function totals(StockMovement $stockMovDocument): void
    {
        \Logger::getLogger(\get_class($this))->debug(__METHOD__);
        if ($stockMovDocument->issetDocumentTotals() === false) {
            $msg           = \sprintf(
                AAuditFile::getI18n()->get("does_not_have_document_totals"),
                $stockMovDocument->getDocumentNumber()
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $stockMovDocument->addError($msg);
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;

            return;
        }

        $totals = $stockMovDocument->getDocumentTotals();
        $gross  = new UDecimal($totals->getGrossTotal(), 2);
        $net    = new UDecimal($totals->getNetTotal(), static::CALC_PRECISION);
        $tax    = new UDecimal($totals->getTaxPayable(), static::CALC_PRECISION);

        if ($gross->equals($net->plus($tax)) === false) {

            $msg = \sprintf(
                AAuditFile::getI18n()->get("document_gross_not_equal_tax_plus_net"),
                $stockMovDocument->getDocumentNumber()
            );

            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $totals->addError($msg);
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
        }

        if ($gross->signedSubtract($this->grossTotal)->abs()->valueOf() > $this->deltaTotalDoc) {
            $msg           = \sprintf(
                AAuditFile::getI18n()->get("document_gross_not_equal_calc_gross"),
                $stockMovDocument->getDocumentNumber()
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $totals->addError($msg, DocumentTotals::N_GROSSTOTAL);
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
        }

        if ($net->signedSubtract($this->netTotal)->abs()->valueOf() > $this->deltaTotalDoc) {
            $msg           = \sprintf(
                AAuditFile::getI18n()->get("document_nettotal_not_equal_calc_nettotal"),
                $stockMovDocument->getDocumentNumber()
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $totals->addError($msg, DocumentTotals::N_NETTOTAL);
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
        }

        if ($tax->signedSubtract($this->taxPayable)->abs()->valueOf() > $this->deltaTotalDoc) {
            $msg           = \sprintf(
                AAuditFile::getI18n()->get("document_taxpayable_not_equal_calc_taxpayable"),
                $stockMovDocument->getDocumentNumber()
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $totals->addError($msg, DocumentTotals::N_TAXPAYABLE);
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
        }

        if ($stockMovDocument->getDocumentTotals()->getCurrency(false) === null) {
            \Logger::getLogger(\get_class($this))->info(
                \sprintf(
                    "StockMovement '%s' without currency node",
                    $stockMovDocument->getDocumentNumber()
                )
            );
            return;
        }

        $currency      = $stockMovDocument->getDocumentTotals()->getCurrency();
        $currAmou      = new UDecimal(
            $currency->getCurrencyAmount(), static::CALC_PRECISION
        );
        $rate          = new UDecimal(
            $currency->getExchangeRate(), static::CALC_PRECISION
        );
        $grossExchange = $currAmou->multiply($rate);
        $stockMovDocument->getDocTotalcal()->setGrossTotalFromCurrency($grossExchange->valueOf());
        $calcCambio    = $gross->signedSubtract($grossExchange, 2)->abs()->valueOf();

        if ($calcCambio > $this->deltaCurrency) {

            $msg = \sprintf(
                AAuditFile::getI18n()->get("document_currency_rate"),
                $stockMovDocument->getDocumentNumber()
            );

            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $totals->addError(
                $msg,
                \Rebelo\SaftPt\AuditFile\SourceDocuments\Currency::N_EXCHANGERATE
            );
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
        }
    }

    /**
     * Test if the signature is valide or not
     * @param \Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\StockMovement $stockMovDocument
     * @return void
     * @since 1.0.0
     */
    protected function sign(StockMovement $stockMovDocument): void
    {
        \Logger::getLogger(\get_class($this))->debug(__METHOD__);
        if ($stockMovDocument->issetHash() === false) {
            $msg           = \sprintf(
                AAuditFile::getI18n()->get("does_not_have_hash"),
                $stockMovDocument->getDocumentNumber()
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $stockMovDocument->addError($msg, StockMovement::N_HASH);
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
            return;
        }

        if ($stockMovDocument->getDocumentStatus()->getSourceBilling()->isEqual(SourceBilling::I)) {
            $validate = true;
        } else {
            $validate = $this->sign->verifySignature(
                $stockMovDocument->getHash(),
                $stockMovDocument->getMovementDate(),
                $stockMovDocument->getSystemEntryDate(),
                $stockMovDocument->getDocumentNumber(),
                $stockMovDocument->getDocumentTotals()->getGrossTotal(),
                $this->lastHash
            );
        }

        if ($validate === false && $this->lastHash === "") {

            list(,, $no) = \explode(
                " ",
                \str_replace("/", " ", $stockMovDocument->getDocumentNumber())
            );

            if ($no !== "1") {
                $msg      = \sprintf(
                    AAuditFile::getI18n()->get("is_valid_only_if_is_not_first_of_serie"),
                    $stockMovDocument->getDocumentNumber()
                );
                \Logger::getLogger(\get_class($this))->info($msg);
                $this->auditFile->getErrorRegistor()->addWarning($msg);
                $stockMovDocument->addWarning($msg);
                $validate = true;
            }
        }

        if ($validate === false) {
            $msg           = \sprintf(
                AAuditFile::getI18n()->get("signature_not_valid"),
                $stockMovDocument->getDocumentNumber()
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            $stockMovDocument->addError($msg, StockMovement::N_HASH);
            \Logger::getLogger(\get_class($this))->debug($msg);
            $this->isValid = false;
        }

        $this->lastHash = $stockMovDocument->getHash();
        if ($validate === false) {
            $this->isValid = false;
        }
    }

    /**
     * Verify the Start and en time of movement
     * @param \Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\StockMovement $stockMovDocument
     * @return void
     */
    public function movementStartAndEndTime(StockMovement $stockMovDocument): void
    {
        if ($stockMovDocument->issetMovementStartTime() === false) {
            $msg = \sprintf(
                AAuditFile::getI18n()->get("stockmov_must_have_mov_start_time"),
                $stockMovDocument->getDocumentNumber()
            );
            $stockMovDocument->addError(
                $msg, StockMovement::N_MOVEMENTSTARTTIME
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
        }

        $startTime = $stockMovDocument->getMovementStartTime();
        if ($startTime->isEarlier($stockMovDocument->getMovementDate())) {
            $msg = \sprintf(
                AAuditFile::getI18n()->get("stockmov_mov_start_time_earlier_doc_date"),
                $stockMovDocument->getDocumentNumber()
            );
            $stockMovDocument->addError(
                $msg, StockMovement::N_MOVEMENTSTARTTIME
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
        }

        if ($startTime->isEarlier($stockMovDocument->getSystemEntryDate())) {
            $msg = \sprintf(
                AAuditFile::getI18n()->get("stockmov_mov_start_time_earlier_systementrydate"),
                $stockMovDocument->getDocumentNumber()
            );
            $stockMovDocument->addError(
                $msg, StockMovement::N_MOVEMENTSTARTTIME
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
        }

        $endTime = $stockMovDocument->getMovementEndTime();
        if ($endTime === null) {
            return;
        }

        if ($endTime->isEarlier($stockMovDocument->getMovementStartTime())) {
            $msg = \sprintf(
                AAuditFile::getI18n()->get("stockmov_end_time_earlier_start_time"),
                $stockMovDocument->getDocumentNumber()
            );
            $stockMovDocument->addError(
                $msg, StockMovement::N_MOVEMENTENDTIME
            );
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
        }
    }

    /**
     * Validate the StockMovement date nad SystemEntrydate
     * @param \Rebelo\SaftPt\AuditFile\SourceDocuments\MovementOfGoods\StockMovement $stockMovDocument
     * @return void
     * @since 1.0.0
     */
    protected function stockMovementDateAndSystemEntryDate(StockMovement $stockMovDocument): void
    {
        $docDate           = $stockMovDocument->getMovementDate();
        $systemDate        = $stockMovDocument->getSystemEntryDate();
        $msgStack          = [];
        $headerDateChecked = false;
        if ($this->auditFile->issetHeader()) {
            $header = $this->auditFile->getHeader();
            if ($header->issetStartDate() && $header->issetEndDate()) {
                if ($header->getStartDate()->isLater($docDate) ||
                    $header->getEndDate()->isEarlier($docDate)) {
                    $msg        = \sprintf(
                        AAuditFile::getI18n()
                            ->get("doc_date_out_of_range_start_end_header_date"),
                        $stockMovDocument->getDocumentNumber()
                    );
                    $msgStack[] = $msg;
                    $stockMovDocument->addError(
                        $msg, StockMovement::N_SYSTEMENTRYDATE
                    );
                }
                $headerDateChecked = true;
            }
        }

        if ($headerDateChecked === false) {
            $msg        = \sprintf(
                AAuditFile::getI18n()
                    ->get("doc_date_not_cheked_start_end_header_date"),
                $stockMovDocument->getDocumentNumber()
            );
            $msgStack[] = $msg;
            $stockMovDocument->addError($msg, StockMovement::N_MOVEMENTDATE);
        }

        if ($this->lastDocDate !== null &&
            $this->lastDocDate->isLater($docDate)) {
            $msg        = \sprintf(
                AAuditFile::getI18n()
                    ->get("doc_date_eaarlier_previous_doc"),
                $stockMovDocument->getDocumentNumber()
            );
            $msgStack[] = $msg;
            $stockMovDocument->addError($msg, StockMovement::N_MOVEMENTDATE);
        }

        if ($this->lastSystemEntryDate !== null &&
            $this->lastSystemEntryDate->isLater($systemDate)) {
            $msg        = \sprintf(
                AAuditFile::getI18n()
                    ->get("doc_systementrydate_earlier_previous_doc"),
                $stockMovDocument->getDocumentNumber()
            );
            $msgStack[] = $msg;
            $stockMovDocument->addError($msg, StockMovement::N_SYSTEMENTRYDATE);
        }

        foreach ($msgStack as $msg) {
            $this->auditFile->getErrorRegistor()->addValidationErrors($msg);
            \Logger::getLogger(\get_class($this))->info($msg);
            $this->isValid = false;
        }
    }
}