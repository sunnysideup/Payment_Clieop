<?php
/**
 * Copyright (c) 2010, The PHP Group
 *
 * All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 * - Redistributions of source code must retain the above copyright
 *   notice, this list of conditions and the following disclaimer.
 * - Redistributions in binary form must reproduce the above copyright
 *   notice, this list of conditions and the following disclaimer in 
 *   the documentation and/or other materials provided with the distribution.
 * - Neither the name of The PHP Group nor the names of its contributors 
 *   may be used to endorse or promote products derived from this
 *   software without specific prior written permission.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS 
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE 
 * COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF
 * USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND 
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, 
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT
 * OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF 
 * SUCH DAMAGE.
 *
 * @author Dave Mertens <dmertens@zyprexia.com>
 * @version $Id$
 * @package Payment_Clieop
 */


/*
 Please note that public function names are partly in Dutch. This is because 
 also the clieop data strings has dutch names. (batchvoorloopinfo, transactieinfo, etc).
 */



/**
 * Main clieop class
 *
 * @version $Revision$
 * @access public
 * @author Dave Mertens <dmertens@zyprexia.com>
 * @package Payment_Clieop
 */
class Payment_Clieop
{
    /**
    * @var string
    * @access private
    */
    var $_SenderIdent;

    /**
    * @var string
    * @access private
    */
    var $_FileIdent;

    /**
    * @var string
    * @access private
    */
    var $_TransactionType;

    /**
    * @var string
    * @access private
    */
    var $_ClieopText;

    /**
    * @var string
    * @access private
    */
    var $_PrincipalAccountNumber;

    /**
    * @var string
    * @access private
    */
    var $_PrincipalName;

    /**
    * @var integer
    * @access private
    */
    var $_BatchNumber;

    /**
    * @var integer
    * @access private
    */
    var $_TotalAmount;

    /**
    * @var string
    * @access private
    */
    var $_AccountChecksum;

    /**
    * @var string
    * @access private
    */
    var $_Description;

    /**
    * @var int
    * @access private
    */
    var $_NumberOfTransactions = 0;

    /**
    * @var date (in DDMMYY format)
    * @access private
    */
    var $_ProcessDate;

    /**
    * @var boolean
    * @access private
    */
    var $_Test;

    /**
    * @var string
    * @access private
    */
    var $_TransactionText;

    /**
    * Constructor for class
    * @return void
    * @access public
    */
    function __construct()
    {
        //init vars
        $this->_ProcessDate = "000000";    //process ASAP
        $this->_BatchNumber = 1;
        $this->_Test = "T";
        return 1;
    }

    /**
    * Adds a payment record to the clieop file
    * @param object paymentObject    - Instance of transactionPayment
    * @access public
    * @return mixed true on success or error
    */
    function addPayment($paymentObject)
    {
        if (is_null($paymentObject))
        {
            return user_error('Payment object cannot be null');
        }
        
        //Only one type of transaction is allowed in a clieop
        if ($this->_TransactionType != $paymentObject->getPaymentType())
        {
            return user_error('Payment transaction type does not match Clieop transaction type');
        }
        
        //Check if amount in transaction is valid (must be > 0)
        $paymentAmount = $paymentObject->getAmount();
        if ($paymentAmount < 0) {
            return user_error('Payment amount cannot be negative: ' . $paymentAmount);
        } 
        elseif ($paymentAmount == 0) {
            return user_error('Payment amount must be nonzero: ' . $paymentAmount);
        }
        
        //transactieinfo (0100)
        $text = $this->writeTransactieInfo($paymentObject->getTransactionType(),
            $paymentObject->getAmount(),
            $paymentObject->getAccountNumberSource(),
            $paymentObject->getAccountNumberDest());
            
        // Debtor name and city
        if (strtoupper($this->_TransactionType) == "DEBTOR")
        {
            // name of debtor (0110)
            $text .= $this->writeNaambetalerInfo($paymentObject->getName());
            // city of debtor (0113)
            $text .= $this->writeWoonplaatsbetalerInfo($paymentObject->getCity());
        }
        
        // betalingskenmerk (0150)
        $text .= $this->writeBetalingskenmerkInfo($paymentObject->getInvoiceReference());
        
        // maximum 4 description lines (0160)
        $descArray = $paymentObject->getDescription();
        while(list($id,$desc) = each($descArray))
        {    
            $text .= $this->writeOmschrijvingInfo($desc);    
        }
        
        //routine splits here into creditor and debtor
        if (strtoupper($this->_TransactionType) == "CREDITOR")
        {
            //name of creditor (0170)
            $text .= $this->writeNaambegunstigdeInfo($paymentObject->getName());
        
            //city of creditor (0173)
            $text .= $this->writeWoonplaatsbegunstigdeInfo($paymentObject->getCity());
        }
        
        //do some calculations
        $this->_NumberOfTransactions++;
        //accoutnumber checksum
        $this->_AccountChecksum += (int)$paymentObject->getAccountNumberSource() + (int)$paymentObject->getAccountNumberDest();
        $this->_TotalAmount += $paymentObject->getAmount();
        $this->_TransactionText .= $text;
        
        //successful
        return true;
    }
    
    /**
    * Writes complete clieop file
    * @access public
    * @return mixed string containing clieop batch, or error
    */
    function writeClieop()
    {
        if ($this->_NumberOfTransactions == 0)
        {
            return user_error('No transactions have been added to this Clieop batch');
        }
            
        $text  = $this->writeBestandsvoorloopInfo($this->_SenderIdent, $this->_BatchNumber);
        $text .= $this->writeBatchvoorloopInfo($this->_PrincipalAccountNumber, $this->_BatchNumber);
        $text .= $this->writeVasteomschrijvingInfo($this->_FixedDescription);
        $text .= $this->writeOpdrachtgeverInfo($this->_ProcessDate, $this->_PrincipalName);
        $text .= $this->_TransactionText;
        $text .= $this->writeBatchsluitInfo();
        $text .= $this->writeBestandssluitInfo();
        
        //return clieop file
        return $text;
    }
    
    /**
    * property BatchNumber
    * @param integer Value    - Number of batches send to day (including this one)
    * @return string
    * @access public
    */
    function getBatchNumber()
    {
        return $this->_BatchNumber;
    }
    function setBatchNumber($Value)
    {
        $this->_BatchNumber = $Value;
    }
    
    /**
    * property FixedDescription
    * @param string Value    - Description which will be added to each transaction payment
    * @return string
    * @access public
    */
    function getFixedDescription()
    {
        return $this->_FixedDescription;
    }
    function setFixedDescription($Value)
    {
        $this->_FixedDescription = $Value;
    }
    
    /**
    * property ProcessDate 
    * @param string Value    - Date in DDMMYY format, required by some banks. Default is 000000 ('as soon as possible').
    * @return string
    * @access public
    */
    function getProcessDate()
    {
        return $this->_ProcessDate;
    }
    function setProcessDate($Value)
    {
        $this->_ProcessDate = $Value;
    }

    /**
    * property SenderIdentification
    * @param string Value    - Identification of sender, free of choice
    * @return string
    * @access public
    */
    function getSenderIdentification()
    {
        return $this->_SenderIdent;
    }
    function setSenderIdentification($Value)
    {
        $this->_SenderIdent = $Value;
    }
    
    /**
    * property PrincipalName
    * @param string Value    - Name of principal
    * @return string
    * @access public
    */
    function getPrincipalName()
    {
        return $this->_PrincipalName;
    }
    function setPrincipalName($Value)
    {
        $this->_PrincipalName = $Value;
    }
    
    /**
    * property PrincipalAccountNumber
    * @param string Value    - Account number of principal
    * @return string
    * @access public
    */
    function getPrincipalAccountNumber()
    {
        return $this->_PrincipalAccountNumber;
    }
    function setPrincipalAccountNumber($Value)
    {
        $this->_PrincipalAccountNumber = $Value;
    }
    
    /**
    * property TransactionType
    * @param string Value    - transaction type
    * @return string
    * @access public
    */
    function getTransactionType()
    {
        return $this->_TransactionType;
    }
    function setTransactionType($Value)
    {
        switch($Value)
        {
            case "00":    //BETALING
                $this->_TransactionType = "CREDITOR";
                $this->_TransactionCode = "00";
                break;
            case "10":    //INCASSO
                $this->_TransactionType = "DEBTOR";
                $this->_TransactionCode = "10";
                break;
        }
    }
    
    /**
    * property Test
    * @param boolean Value    - true = test clieop, false = production clieop
    * @return string
    * @access public
    */
    function getTest()
    {
        return $this->_Test;
    }
    function setTest($Value)
    {
        if ($Value == false)
            $this->_Test = "P";    //production clieop
        else
            $this->_Test = "T";    //test clieop
    }
        
    
    /**
    * INFOCODE: 0100
    * Writes transaction header
    * @param string transType            - Type of transaction ('0000' for betaling, '1002' for incasso)
    * @param integer amount                - Payment amount in Eurocents
    * @param string accountNumberSource    - Source bankaccount number 
    * @param string accountNumberDest    - Destination bankaccount number
    * @access private
    * @return string
    */
    function writeTransactieInfo($transType, $amount, $accountNumberSource, $accountNumberDest)
    {
        $text  = "0100";                                        //infocode
        $text .= "A";                                            //variantcode
        $text .= $this->numFiller($transType, 4);                //transactiesoort
        $text .= $this->numFiller($amount, 12);                    //Bedrag
        $text .= $this->numFiller($accountNumberSource, 10);    //Reknr betaler
        $text .= $this->numFiller($accountNumberDest, 10);        //Reknr begunstigde
        $text .= $this->filler(9);
        
        //return clieop line
        return $text;
    }
    
    /**
    * INFOCODE: 0150
    * Writes invoice reference clieop line
    * @param string invoiceReference    - Reference of invoice
    * @access private
    * @return string
    */
    function  writeBetalingskenmerkInfo($invoiceReference)
    {
        $text  = "0150";                                    //infocode
        $text .= "A";                                        //variantcode
        $text .= $this->alfaFiller($invoiceReference, 16);    //betalings kenmerk
        $text .= $this->filler(29);
        
        //return clieop line
        if (strlen($invoiceReference) > 0) return $text;    //only return string if there's really a value
    }    

    /**
    * INFOCODE: 0160
    * Writes an description for the clieop file
    * @param string description    - Description of payment (Can be called maximum 4 times!)
    * @access private
    * @return string
    */
    function writeOmschrijvingInfo($description)
    {
        $text  = "0160";                                    //infocode
        $text .= "A";                                        //variantcode
        $text .= $this->alfaFiller($description, 32);        //omschrijving van post
        $text .= $this->filler(13);
        
        //return clieop line
        return $text;
    }
    
    /**
    * INFOCODE: 0170
    * Write the creditor name record 
    * @param string name     - Name of creditor
    * @access private
    * @return string
    */
    function writeNaambegunstigdeInfo($name)
    {
        $text  = "0170";                                    //infocode
        $text .= "B";                                        //variantcode
        $text .= $this->alfaFiller($name, 35);                //naam begunstigde
        $text .= $this->filler(10);
        
        //reurn clieop line
        return $text;
    }

    /**
    * INFOCODE: 0173
    * Write the creditor city record 
    * @param string city     - City of creditor
    * @access private
    * @return string
    */
    function writeWoonplaatsbegunstigdeInfo($city)
    {
        $text  = "0173";                                    //infocode
        $text .= "B";                                        //variantcode
        $text .= $this->alfaFiller($city, 35);                //woonplaats begunstigde
        $text .= $this->filler(10);
        
        //reurn clieop line
        return $text;
    }
    /**
    * INFOCODE: 0110
    * Write the debtor name record 
    * @param string name     - Name of debtor
    * @access private
    * @return string
    */
    function writeNaambetalerInfo($name)
    {
        $text  = "0110";                                    //infocode
        $text .= "B";                                        //variantcode
        $text .= $this->alfaFiller($name, 35);                //naam betaler
        $text .= $this->filler(10);
        
        //reurn clieop line
        return $text;
    }

    /**
    * INFOCODE: 0113
    * Write the debtor city record 
    * @param string city     - City of debtor
    * @access private
    * @return string
    */
    function writeWoonplaatsbetalerInfo($city)
    {
        $text  = "0113";                                    //infocode
        $text .= "B";                                        //variantcode
        $text .= $this->alfaFiller($city, 35);                //woonplaats betaler
        $text .= $this->filler(10);
        
        //reurn clieop line
        return $text;
    }
    
    /**
    * INFOCODE: 0001
    * Write clieop header 
    * @param string identifier    - 5 char sender identification (free of choice)
    * @param integer batchCount    - Numbers of clieop batches send today + 1
    * @access private
    * @return string
    */
    function writeBestandsvoorloopInfo($identifier, $batchCount)
    {
        $text  = "0001";                                        //infocode
        $text .= "A";                                            //variantcode
        $text .= date("dmy");                                    //aanmaak datum
        $text .= "CLIEOP03";                                    //bestands naam
        $text .= $this->alfaFiller($identifier, 5);                //afzender identificatie
        $text .= date("d") . $this->numFiller($batchCount, 2);    //bestands identificatie 
        $text .= "1";                                            //duplicaat code
        $text .= $this->filler(21);    
        
        //return cliep line
        return $text;
    }
    
    /**
    * INFOCODE: 9999
    * Write clieop footer 
    * @access private
    * @return string
    */
    function writeBestandssluitInfo()
    {
        $text  = "9999";                                    //infocode
        $text .= "A";                                        //variantcode
        $text .= $this->filler(45);
        
        //return cleip line
        return $text;
    }
    
    /**
    * INFOCODE: 0010
    * Write clieop batchvoorloopinfo
    * @param string principalAccountNumber    - Account number of principal
    * @param integer batchCount                - Number of batches send this month (including this one) 
    * @access private
    * @return string
    */
    function writeBatchvoorloopInfo($principalAccountNumber, $batchCount)
    {    
        $text  = "0010";                                        //infocode
        $text .= "B";                                            //variantcode
        $text .= $this->numFiller($this->_TransactionCode, 2);    //transactiegroep (00 = betaling, 10 = incasso)
        $text .= $this->numFiller($principalAccountNumber, 10);    //rekening nummer opdrachtgever
        $text .= $this->numFiller($batchCount, 4);                //batch volgnummer
        $text .= "EUR";                                            //aanlevering muntsoort
        $text .= $this->filler(26);
        
        //return clieop line
        return $text;
    }
    
    /**
    * INFOCODE: 0020
    * Write clieop batchvoorloopinfo
    * @access string description    - Fixed description for all payments
    * @access private
    * @return string
    */
    function writeVasteomschrijvingInfo($description)
    {
        $text  = "0020";                                        //infocode
        $text .= "A";                                            //variantcode
        $text .= $this->alfaFiller($description, 32);            //vaste omschrijving
        $text .= $this->filler(13);
        
        //return clieop line
        if (strlen($description) > 0) return $text;                //only return string if there is REALLY a description
    }

    /**
    * INFOCODE: 0030
    * Write opdrachtegever clieop line
    * @param date processDate        - Process date in DDMMYY-format
    * @param string principalName    - Name of pricipal
    * @access private
    * @return string
    */
    function writeOpdrachtgeverInfo($processDate, $principalName)
    {
        $text  = "0030";                                        //infocode
        $text .= "B";                                            //variantcode
        $text .= "1";                                            //NAWcode
        $text .= $this->numFiller($processDate, 6);                //verwerkings datum
        $text .= $this->alfaFiller($principalName, 35);            //naam opdracht gever
        $text .= $this->_Test;                                    //TESTcode (T = Test, P = Productie)
        $text .= $this->filler(2);
        
        //return clieop line
        return $text;
    }
    
    /**
    * INFOCODE: 9990
    * Write clieop batchsluitinfo
    * @access private
    * @return string
    */
    function writeBatchsluitInfo()
    {
        $text  = "9990";                                            //infocode
        $text .= "A";                                                //variantcode
        $text .= $this->numFiller($this->_TotalAmount, 18);            //Totaalbedrag clieop
        $text .= $this->numFiller($this->_AccountChecksum, 10);        //checksum van rekeningnummers
        $text .= $this->numFiller($this->_NumberOfTransactions, 7);    //Aantal transactie posten
        $text .= $this->filler(10);
        
        //return clieop line
        return $text;
    }


    /**
    * @var string
    * @access private
    */
    var $_NewLine = "\n";
    
    /**
    * property NewLine
    * @param string Value   - New line character to use in Clieop output (defaults to "\n"; some banks require "\r\n")
    * @return string
    * @access public
    */
    function getNewLine()
    {
        return $this->_NewLine;
    }
    function setNewLine($Value)
    {
        $this->_NewLine = $Value;
    }    
    
    /**
    * Alfa numeric filler
    * @param string text    - Text which needs to filled up
    * @param integer length    - The length of the required text
    * @return string
    * @access public
    */
    function alfaFiller($text, $length)
    {
        //how many spaces do we need?
        $alfaLength = abs($length - strlen($text));
        
        //return string with spaces on right side
        return substr($text . str_repeat(" ", $alfaLength), 0, $length);
    }
    
    /**
    * Numeric filler
    * @param string number    - number which needs to filled up (Will be converted to a string)
    * @param integer length    - The length of the required number
    * @return string
    * @access public
    */
    function numFiller($number, $length)
    {
        //how many zeros do we need
        settype($number, "string");        //We need to be sure that number is a string. 001 will otherwise be parsed as 1
        $numberLength = abs($length - strlen($number));
        
        //return original number woth zeros on the left
        return substr(str_repeat("0", $numberLength) . $number, -$length);
    }
    
    /**
    * filler
    * @param integer length    - How many filler spaces do we need
    * @return string
    * @access public
    */
    function filler($Length)
    {
        return str_repeat(" ", $Length) . $this->_NewLine;
    }
}


/**
 * Copyright (c) 2010, The PHP Group
 *
 * All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 * - Redistributions of source code must retain the above copyright
 *   notice, this list of conditions and the following disclaimer.
 * - Redistributions in binary form must reproduce the above copyright
 *   notice, this list of conditions and the following disclaimer in 
 *   the documentation and/or other materials provided with the distribution.
 * - Neither the name of The PHP Group nor the names of its contributors 
 *   may be used to endorse or promote products derived from this
 *   software without specific prior written permission.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS 
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE 
 * COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF
 * USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND 
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, 
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT
 * OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF 
 * SUCH DAMAGE.
 *
 * @author Dave Mertens <dmertens@zyprexia.com>
 * @version $Id$
 * @package Payment_Clieop
 */


//Define some constants for easy programming  ;-)

/**
* Constant for debtor transactions
* @const CLIEOP_TRANSACTIE_INCASSO Clieop transaction code for debtor transactions
*/
define( "CLIEOP_TRANSACTIE_INCASSO", "10" );    //Incasso transaction type (debtor)

/**
* Constant for creditor transactions
* @const CLIEOP_TRANSACTIE_BETALING Clieop transaction code for creditor transactions
*/
define( "CLIEOP_TRANSACTIE_BETALING", "00" );     //betaling transaction type (creditor)

/**
* Data holder for payment post
*
* Please note that some function names are partly in Dutch. Clieop03 is
* a Dutch banking standard and they have chosen to use Dutch line descriptions
*
* The Payment_Clieop_Transaction class is a data-holder for the main clieop class.
*
* @version $Revision$
* @access public
* @package Payment_Clieop
* @author Dave Mertens <dmertens@zyprexia.com>
*/
class Payment_Clieop_Transaction
{
    /**
    * @var string
    * @access private
    * @values 0000 (creditor) or 1002 (Debtor)
    */
    var $_TransactionType;

    /**
    * @var numeric string
    * @access private
    * @values CREDITOR or DEBTOR
    */
    var $_TransactionName;

    /**
    * @var integer
    * @access private
    */
    var $_Amount;

    /**
    * @var string
    * @access private
    */
    var $_AccountNumberSource;

    /**
    * @var string
    * @access private
    */
    var $_AccountNumberDest;

    /**
    * @var string
    * @access private
    */
    var $_InvoiceReference;

    /**
    * @var string
    * @access private
    */
    var $_Type;

    /**
    * @var string
    * @access private
    */
    var $_Name;

    /**
    * @var string
    * @access private
    */
    var $_City;

    /**
    * @var string
    * @access private
    */
    var $_Desciption;
    
    /**
    * Constructor for class
    * @param string transactionType        - constant CLIEOP_TRANSACTIE_INCASSO or CLIEOP_TRANSACTIE_BETALING
    * @return void
    * @access public
    */
    function __construct($transactionType)
    {
        $this->_Description = array();
        if ($transactionType == "00")
        {
            //creditor payment
            $this->_TransactionType = "0000";
            $this->_TransactionName = "CREDITOR";
        }
        else
        {
            //debtor payment
            $this->_TransactionType = "1002";
            $this->_TransactionName = "DEBTOR";
        }
    }
    
    /**
    * Fetch payment type
    * @return string
    * @access public
    */
    function getPaymentType()
    {
        return $this->_TransactionName;    //return type of class
    }
    
    /**
    * return transaction type
    * @return string
    * @access public
    */
    function getTransactionType()
    {
        return $this->_TransactionType;    //return special transaction type
    }

    /**
    * Property amount (in Eurocents)
    * @param integer Value    - Payment amount in euro cents (Rounded on 2 digits). Must be a positive number.
    * @return integer
    * @access public
    */
    function getAmount()
    {
        return $this->_Amount;
    }
    function setAmount($Value)
    {
        $this->_Amount = $Value;
    }
    
    /**
    * property AccountNumberSource
    * @param string Value    - Source bank account number (Max 10 tokens)
    * @return string
    * @access public
    */
    function getAccountNumberSource()
    {
        return $this->_AccountNumberSource;
    }
    function setAccountNumberSource($Value)
    {
        $this->_AccountNumberSource = $Value;
    }
    
    /**
    * property AccountNumberDest
    * @param string Value    - Destination bankaccount number
    * @return string
    * @access public
    */
    function getAccountNumberDest()
    {
        return $this->_AccountNumberDest;
    }
    function setAccountNumberDest($Value)
    {
        $this->_AccountNumberDest = $Value;
    }
    
    /**
    * property InvoiceReference 
    * @param string Value    - Invoice reference (Max 16 tokens)
    * @return string
    * @access public
    */
    function getInvoiceReference()
    {
        return $this->_InvoiceReference;
    }
    function setInvoiceReference($Value)
    {
        $this->_InvoiceReference = $Value;
    }
    
    /**
    * property Name
    * @param string Value    - Name of creditor or debtor
    * @return string
    * @access public
    */
    function getName()
    {
        return $this->_Name;
    }
    function setName($Value)
    {
        $this->_Name = $Value;
    }
    
    /**
    * property City
    * @param string Value    - City of creditor or debtor
    * @return string
    * @access public
    */
    
    function getCity()
    {
        return $this->_City;
    }
    function setCity($Value)
    {
        $this->_City = $Value;
    }
    
    /**
    * property Description
    * @param string Value    - Description for payment (Maximum 4 description lines)
    * @return array
    * @access public
    */
    function getDescription()
    {
        //return description array
        return $this->_Description;    
    }
    function setDescription($Value)
    {
        //only 4 descriptions are allowed for a payment post
        if (sizeof($this->_Description) < 5)
        {
            $this->_Description[] = $Value;
        }
    }
}

