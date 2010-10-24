<?php
/**
* Clieop DEBTOR sample
*
* $Revision$
*/
include_once("Payment/Clieop.php");
include_once("Payment/Clieop/Transaction.php");

header("Content-type: text/plain");

$clieopFile = new Payment_Clieop();

//set clieop properties
$clieopFile->setTransactionType(CLIEOP_TRANSACTIE_INCASSO);		// debtor transactions
$clieopFile->setPrincipalAccountNumber("123456789");			// principal bank account number
$clieopFile->setPrincipalName("PEAR CLIEOP CLASSES");			// Name of owner of principal account number
$clieopFile->setFixedDescription("PHP: Scripting the web");		// description for all transactions
$clieopFile->setSenderIdentification("PEAR");					// Free identification
$clieopFile->setTest(true);										// Test clieop


//create debtor
$debtor = new Payment_Clieop_Transaction(CLIEOP_TRANSACTIE_INCASSO);
$debtor->setAccountNumberSource("192837346");					// my bank account number
$debtor->setAccountNumberDest("123456789");						// principal bank account number
$debtor->setAmount(12995);										// amount in Eurocents (EUR 129.95)
$debtor->setName("Dave Mertens");								// Name of debtor (holder of source account)
$debtor->setCity("Rotterdam");									// City of debtor
$debtor->setDescription("Ordernumber: 8042");					// Just some info
$debtor->setDescription("Customernumber: 17863");				// about the transaction

//assign debtor record to clieop
$result = $clieopFile->addPayment($debtor);
if (PEAR::isError($result)) {
	echo "Error from addPayment: ".$result->getMessage()."\n";
}


//Create clieop file
$result = $clieopFile->writeClieop();
if (PEAR::isError($result)) {
	echo "Error from writeClieop: ".$result->getMessage()."\n";
}

echo $result;
	
?>