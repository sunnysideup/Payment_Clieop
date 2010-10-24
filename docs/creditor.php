<?php
/**
* Clieop CREDITOR sample
*
* $Revision$
*/
include_once("Payment/Clieop.php");
include_once("Payment/Clieop/Transaction.php");

header("Content-type: text/plain");

$clieopFile = new Payment_Clieop();

//set clieop properties
$clieopFile->setTransactionType(CLIEOP_TRANSACTIE_BETALING);	// debtor transactions
$clieopFile->setPrincipalAccountNumber("123456789");			// principal bank account number
$clieopFile->setPrincipalName("PEAR CLIEOP CLASSES");			// Name of owner of principal account number
$clieopFile->setFixedDescription("PHP: Scripting the web");		// description for all transactions
$clieopFile->setSenderIdentification("PEAR");					// Free identification
$clieopFile->setTest(true);										// Test clieop


//create creditor
$creditor = new Payment_Clieop_Transaction(CLIEOP_TRANSACTIE_BETALING);
$creditor->setAccountNumberSource("192837346");					// my bank account number
$creditor->setAccountNumberDest("123456789");					// principal bank account number
$creditor->setAmount(6900);										// amount in Eurocents (EUR 69.00)
$creditor->setName("Dave Mertens");								// Name of creditor (holder of source account)
$creditor->setCity("Rotterdam");								// City of creditor
$creditor->setDescription("Like we promised, your money");				// Just some info

//assign creditor record to clieop
$result = $clieopFile->addPayment($creditor);
if (PEAR::isError($result))
{
	echo "Error from addPayment: ".$result->getMessage()."\n";
}

//Create clieop file
$result = $clieopFile->writeClieop();
if (PEAR::isError($result))
{
	echo "Error from writeClieop: ".$result->getMessage()."\n";
}

echo $result;
