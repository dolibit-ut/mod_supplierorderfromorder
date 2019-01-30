<?php


// load conf
include_once __DIR__ . '/config.php';
dol_include_once('subtotal/class/subtotal.class.php');
dol_include_once('fourn/class/fournisseur.commande.class.php');
dol_include_once('fourn/class/fournisseur.product.class.php');
dol_include_once('supplierorderfromorder/lib/function.lib.php');

if(empty($user->rights->fournisseur->commande->creer)) accessforbidden();

$langs->loadLangs(array(
     'admin'
    ,'orders'
    ,'sendings'
    ,'companies'
    ,'bills'
    ,'supplier_proposal'
    ,'deliveries'
    ,'products'
    , 'stocks'
    ,'supplierorderfromorder@supplierorderfromorder'
    
));




$action = GETPOST('action');
$fromid = GETPOST('fromid', 'int');
$from = GETPOST('from');
$TDispatch = array();

if(empty($action)){
    $action = 'prepare';
}

$form = new Form($db);


if(empty($from) || $from != 'commande'){
    exit();
}

if($from ==  'commande'){
	dol_include_once('commande/class/commande.class.php');
	$origin = New Commande($db);
}

if($origin->fetch($fromid) <= 0)
{
    exit($langs->trans('NothingToView'));
}

// Std lines
$TChecked  = GETPOST('checked','array');
$TfournUnitPrice = GETPOST('fournUnitPrice','array');
//$TfournUnitPrice = array_map('intval', $TfournUnitPrice);
$TShipping  = GETPOST('shipping','array');
$Tproductfournpriceid = GETPOST('productfournpriceid','array');
$Tproductfournpriceid = array_map('intval', $Tproductfournpriceid);
$Tqty = GETPOST('qty','array');
$TlistContact = GETPOST('shipping','array');

// Nomenclature
$TNomenclature_productfournpriceid = GETPOST('nomenclature_productfournpriceid', 'array');
$TNomenclature_qty = GETPOST('nomenclature_qty', 'array');
$TNomenclature_fournUnitPrice = GETPOST('nomenclature_fournUnitPrice','array');
$TNomenclature_productfournproductid = GETPOST('nomenclature_productfournproductid', 'array');
$TCheckedNomenclature = GETPOST('checkedNomenclature','array');

/*
 * Actions
 */

$parameters=array();
$reshook=$hookmanager->executeHooks('doActions',$parameters,$object);    // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');

if (empty($reshook))
{
	// do action from GETPOST ... 
	if($action == 'dispatch')
	{

	    
	    $saveconf_SUPPLIER_ORDER_WITH_NOPRICEDEFINED = !empty($conf->global->SUPPLIER_ORDER_WITH_NOPRICEDEFINED)?$conf->global->SUPPLIER_ORDER_WITH_NOPRICEDEFINED:0 ;
	    $conf->global->SUPPLIER_ORDER_WITH_NOPRICEDEFINED = 1;
	    

        foreach ($origin->lines as $i => $line)
        {
            
            if(!empty($TChecked) && in_array($line->id, $TChecked))
            {
               
                
                
                
                $array_options = array();
                if(!empty($TShipping[$line->id])) $TShipping = array_map('intval', $TShipping);
                

                
                $supplierSocId = GETPOST('fk_soc_fourn_'.$line->id, 'int');
                
                // Get fourn from supplier price
                if(isset($Tproductfournpriceid[$line->id])){
                    $prod_supplier = new ProductFournisseur($db);
                    if($prod_supplier->fetch_product_fournisseur_price($Tproductfournpriceid[$line->id]) < 1){
                        // ERROR
                        // sauvegarde des infos pour l'affichage du resultat
                        $TDispatch[$line->id] = array(
                            'status' => -1,
                            'msg' => $langs->trans('ErrorPriceDoesNotExist').' : '.$Tproductfournpriceid[$line->id]
                        );
                        
                        continue;
                    }
                    
                    if(!empty($prod_supplier->fourn_id))
                    {
                        $supplierSocId = $prod_supplier->fourn_id;
                    }
                    
                }
                
                
                
                if(empty($supplierSocId) && ( empty($TDispatch[$line->id]['status']) || $TDispatch[$line->id]['status'] < 0) ){
                    $TDispatch[$line->id] = array(
                        'status' => -1,
                        'msg' => $langs->trans('ErrorFournDoesNotExist').' : '.$supplierSocId
                    );
                    
                    continue;
                }
                
                
                
                
                
                // vérification si la ligne fait déjà l'objet d'une commande fournisseur
                $searchSupplierOrderLine = getLinkedSupplierOrdersLinesFromElementLine($line->id);
                
                if(empty($searchSupplierOrderLine))
                {
                    $createNewOrder = true;
                    
                    $shippingContactId = 0;
                    if(!empty($TShipping[$line->id])){
                        $shippingContactId = $TShipping[$line->id];
                    }
                    
                    $societe = new Societe($db);
                    $societe->fetch($supplierSocId);
                    
                    
                    // search and get draft supplier order linked
                    $searchSupplierOrder = getLinkedSupplierOrderFromOrder($line->fk_commande,$supplierSocId,$shippingContactId,CommandeFournisseur::STATUS_DRAFT,$array_options);
                    if(empty($searchSupplierOrder))
                    {
                        // search draft supplier order with same critera 
                        $searchSupplierOrder = getSupplierOrderAvailable($supplierSocId,$shippingContactId,$array_options);
                    }
                    
                    
                    $CommandeFournisseur = new CommandeFournisseur($db);
                    
                    if(!empty($searchSupplierOrder))
                    {
                        $CommandeFournisseur->fetch($searchSupplierOrder[0]);
                    }
                    else
                    {
                        $CommandeFournisseur->socid = $supplierSocId;
                        $CommandeFournisseur->mode_reglement_id = $societe->mode_reglement_supplier_id;
                        $CommandeFournisseur->cond_reglement_id = $societe->cond_reglement_supplier_id;
                        $id = $CommandeFournisseur->create($user);
                        if($id>0)
                        {
                            // Add shipping contact
                            if(!empty($TShipping[$line->id])){
                                $CommandeFournisseur->add_contact($TShipping[$line->id], 'SHIPPING');
                            }
                            
                            // add order in linked element
                            $CommandeFournisseur->add_object_linked('commande', $origin->id);
                            
                        }
                    }
                    
                    
                    
                    // Vérification de la commande
                    if(empty($CommandeFournisseur->id))
                    {
	                    // sauvegarde des infos pour l'affichage du resultat
	                    $TDispatch[$line->id] = array(
	                        'status' => -1,
	                        'msg' => $langs->trans('ErrorOrderDoesNotExist')
	                    );
                    
                       continue;
                    }
                    
                   
                    // GET PRICE
                    $fk_prod_fourn_price =0;
                    $ref_supplier=$line->ref_fourn;
                    $remise_percent=0.0;
                    $price = 0;
                    $qty = isset($Tqty[$line->id])?$Tqty[$line->id]:$line->qty;
                    $tva_tx = $line->tva_tx;
                    
                    if(!empty($TfournUnitPrice[$line->id])){
                        $price = price2num($TfournUnitPrice[$line->id]);
                    }
                    
                    // Get subprice from product
                    if(!empty($line->fk_product)){
                        $ProductFournisseur = new ProductFournisseur($db);
                        if($ProductFournisseur->find_min_price_product_fournisseur($line->fk_product, $qty, $supplierSocId)>0){
                            $price = floatval($ProductFournisseur->fourn_price); // floatval is used to remove non used zero
                            $tva_tx = $ProductFournisseur->tva_tx;
                            $fk_prod_fourn_price = $ProductFournisseur->product_fourn_price_id;
                            $remise_percent = $ProductFournisseur->fourn_remise_percent;
                            $ref_supplier= $ProductFournisseur->ref_supplier;
                        }
                    }

                    //récupération du prix d'achat de la line si pas de prix fournisseur
                    if(empty($price) && !empty($line->pa_ht) ){
                        $price = $line->pa_ht;
                    }
                   
                    
                    // ADD LINE
                    $addRes = $CommandeFournisseur->addline(
                        $line->desc,
                        $price,
                        $qty,
                        $tva_tx,
                        $txlocaltax1=0.0, 
                        $txlocaltax2=0.0, 
                        $line->fk_product, 
                        $fk_prod_fourn_price, 
                        $ref_supplier, 
                        $remise_percent, 
                        'HT', 
                        0, //$pu_ttc=0.0, 
                        $line->product_type, 
                        $line->info_bits, 
                        false, //$notrigger=false, 
                        null, //$date_start=null, 
                        null, //$date_end=null, 
                        0, //$array_options=0, 
                        $line->fk_unit, 
                        0,//$pu_ht_devise=0, 
                        'commandedet', //$origin= // peut être un jour ça sera géré...
                        $line->id //$origin_id=0 // peut être un jour ça sera géré...
                        );
                   
                    
                    if($addRes>0)
                    {
                        
                        // add order line in linked element
                        $commandeFournisseurLigne = new CommandeFournisseurLigne($db);
                        $commandeFournisseurLigne->fetch($addRes);
                        $commandeFournisseurLigne->add_object_linked('commandedet', $line->id);
                        
                        // sauvegarde des infos pour l'affichage du resultat
                        $TDispatch[$line->id] = array(
                            'status' => 1,
                            'id' => $CommandeFournisseur->id,
                            'msg' => $CommandeFournisseur->getNomUrl(1)
                        );
                    }
                    else {
                        // sauvegarde des infos pour l'affichage du resultat
                        $TDispatch[$line->id] = array(
                            'status' => -1,
                            'id' => $CommandeFournisseur->id,
                            'msg' => $CommandeFournisseur->getNomUrl(1).' '.$langs->trans('ErrorAddSupplierLine').' : '.$commandeFournisseurLigne->error
                        );
                    }
                    
                    
                    
                    
                    
                }
            }
            
            
            
            /**********************/
            /* NOMENCLATURE PART  */
            /**********************/
            if(!empty($TCheckedNomenclature[$line->id]) && is_array($TCheckedNomenclature[$line->id]))
            {
                // Nomenclature
                foreach($TCheckedNomenclature[$line->id] as $nomenclatureI)
                {
                    
                    /*$TNomenclature_productfournpriceid[$line->id][$nomenclatureI];
                    $TNomenclature_qty[$line->id][$nomenclatureI];
                    $TNomenclature_fournUnitPrice[$line->id][$nomenclatureI];*/
                    
                    
                    $forceSupplierSocId = GETPOST('force_nomenclature_fk_soc_fourn_'.$line->id, 'int');
                    
                    if(empty($TNomenclature_productfournproductid[$line->id][$nomenclatureI]) && empty($forceSupplierSocId))
                    {
                        $TDispatchNomenclature[$line->id][$nomenclatureI] = array(
                            'status' => -1,
                            'msg' => $langs->trans('ErrorNoProduct').' : '.$nomenclatureI
                        );
                        
                        continue;
                    }
                    
                    $product = new Product($db);
                    if( $product->fetch((int)$TNomenclature_productfournproductid[$line->id][$nomenclatureI])  < 1 )
                    {
                        $TDispatchNomenclature[$line->id][$nomenclatureI] = array(
                            'status' => -1,
                            'msg' => $langs->trans('ErrorNoProductFound').' : '.$TNomenclature_productfournproductid[$line->id][$nomenclatureI]
                        );
                        
                        continue;
                    }
                    
                    
                    
                    $supplierSocId = GETPOST('fk_soc_fourn_'.$line->id.'_n'.$nomenclatureI, 'int');
                    
                    if(!empty($forceSupplierSocId)){
                        $supplierSocId = $forceSupplierSocId;
                    }
                    
                    // Get fourn from supplier price
                    if(!empty($forceSupplierSocId) && isset($TNomenclature_productfournpriceid[$line->id][$nomenclatureI])){
                        $prod_supplier = new ProductFournisseur($db);
                        if($prod_supplier->fetch_product_fournisseur_price($TNomenclature_productfournpriceid[$line->id][$nomenclatureI]) < 1){
                            // ERROR
                            // sauvegarde des infos pour l'affichage du resultat
                            $TDispatchNomenclature[$line->id][$nomenclatureI] = array(
                                'status' => -1,
                                'msg' => $langs->trans('ErrorPriceDoesNotExist').' : '.$TNomenclature_productfournpriceid[$line->id][$nomenclatureI]
                            );
                            
                            continue;
                        }
                        
                        if(!empty($prod_supplier->fourn_id))
                        {
                            $supplierSocId = $prod_supplier->fourn_id;
                        }
                        
                    }
                    
                    
                    if(empty($supplierSocId) && ( empty($TDispatchNomenclature[$line->id]['status']) || $TDispatchNomenclature[$line->id]['status'] < 0) ){
                        $TDispatchNomenclature[$line->id][$nomenclatureI] = array(
                            'status' => -1,
                            'msg' => $langs->trans('ErrorFournDoesNotExist').' : '.$supplierSocId
                        );
                        
                        continue;
                    }
                    
                    
                    // vérification si la ligne fait déjà l'objet d'une commande fournisseur
                    $searchSupplierOrderLine = false; // TODO : find a way to detect this for nomenclature 
                    
                    if(empty($searchSupplierOrderLine))
                    {
                        $createNewOrder = true;
                        
                        $shippingContactId = 0; // Les produits issue d'une nomenclature ne doivent pas partir dirrectement chez un client
                        
                        $societe = new Societe($db);
                        $societe->fetch($supplierSocId);
                        
                        
                        // search and get draft supplier order linked
                        $searchSupplierOrder = getLinkedSupplierOrderFromOrder($line->fk_commande,$supplierSocId,$shippingContactId,CommandeFournisseur::STATUS_DRAFT,$array_options);
                        if(empty($searchSupplierOrder))
                        {
                            // search draft supplier order with same critera
                            $searchSupplierOrder = getSupplierOrderAvailable($supplierSocId,$shippingContactId,$array_options);
                        }
                        
                        
                        $CommandeFournisseur = new CommandeFournisseur($db);
                        
                        if(!empty($searchSupplierOrder))
                        {
                            $CommandeFournisseur->fetch($searchSupplierOrder[0]);
                        }
                        else
                        {
                            $CommandeFournisseur->socid = $supplierSocId;
                            $CommandeFournisseur->mode_reglement_id = $societe->mode_reglement_supplier_id;
                            $CommandeFournisseur->cond_reglement_id = $societe->cond_reglement_supplier_id;
                            $id = $CommandeFournisseur->create($user);
                            if($id>0)
                            {
                                // add order in linked element
                                $CommandeFournisseur->add_object_linked('commande', $origin->id);
                            }
                        }
                        
                        
                        
                        // Vérification de la commande
                        if(empty($CommandeFournisseur->id))
                        {
                            // sauvegarde des infos pour l'affichage du resultat
                            $TDispatchNomenclature[$line->id][$nomenclatureI] = array(
                                'status' => -1,
                                'msg' => $langs->trans('ErrorOrderDoesNotExist')
                            );
                            
                            continue;
                        }
                        
                        
                        // GET PRICE
                        $fk_prod_fourn_price =0;
                        $ref_supplier='';
                        $remise_percent=0.0;
                        $price = 0;
                        $qty = isset($TNomenclature_qty[$line->id][$nomenclatureI])?$TNomenclature_qty[$line->id][$nomenclatureI]:0;
                        $tva_tx = $line->tva_tx; // An other idea ?
                        
                        if(!empty($TNomenclature_fournUnitPrice[$line->id][$nomenclatureI])){
                            $price = price2num($TNomenclature_fournUnitPrice[$line->id][$nomenclatureI]);
                        }
                        
                        // Get subprice from product
                        if(!empty($line->fk_product)){
                            $ProductFournisseur = new ProductFournisseur($db);
                            if($ProductFournisseur->find_min_price_product_fournisseur($product->id, $qty, $supplierSocId)>0){
                                $price = floatval($ProductFournisseur->fourn_price); // floatval is used to remove non used zero
                                $tva_tx = $ProductFournisseur->tva_tx;
                                $fk_prod_fourn_price = $ProductFournisseur->product_fourn_price_id;
                                $remise_percent = $ProductFournisseur->fourn_remise_percent;
                                $ref_supplier= $ProductFournisseur->ref_supplier;
                            }
                        }
                        
                        //récupération du prix d'achat de la produit si pas de prix fournisseur
                        /*if(empty($price) && !empty($line->pa_ht) ){
                            $price = $line->pa_ht;
                        }*/
                        
                        
                        // ADD LINE
                        $addRes = $CommandeFournisseur->addline(
                            $line->desc,
                            $price,
                            $qty,
                            $tva_tx,
                            $txlocaltax1=0.0,
                            $txlocaltax2=0.0,
                            $product->id,
                            $fk_prod_fourn_price,
                            $ref_supplier,
                            $remise_percent,
                            'HT',
                            0, //$pu_ttc=0.0,
                            $product->type,
                            0,
                            false, //$notrigger=false,
                            null, //$date_start=null,
                            null, //$date_end=null,
                            0, //$array_options=0,
                            $product->fk_unit,
                            0,//$pu_ht_devise=0,
                            'commandedet', //$origin= // peut être un jour ça sera géré...
                            $line->id //$origin_id=0 // peut être un jour ça sera géré...
                            );
                        
                        
                        if($addRes>0)
                        {
                            
                            // add order line in linked element
                            $commandeFournisseurLigne = new CommandeFournisseurLigne($db);
                            $commandeFournisseurLigne->fetch($addRes);
                            $commandeFournisseurLigne->add_object_linked('commandedet', $line->id);
                            
                            // sauvegarde des infos pour l'affichage du resultat
                            $TDispatchNomenclature[$line->id][$nomenclatureI] = array(
                                'status' => 1,
                                'id' => $CommandeFournisseur->id,
                                'msg' => $CommandeFournisseur->getNomUrl(1)
                            );
                        }
                        else {
                            // sauvegarde des infos pour l'affichage du resultat
                            $TDispatchNomenclature[$line->id][$nomenclatureI] = array(
                                'status' => -1,
                                'id' => $CommandeFournisseur->id,
                                'msg' => $CommandeFournisseur->getNomUrl(1).' '.$langs->trans('ErrorAddSupplierLine').' : '.$commandeFournisseurLigne->error
                            );
                        }
                        
                        
                        
                        
                        
                    }
                    
                    
                    
                }
            }
            
	        
	        $conf->global->SUPPLIER_ORDER_WITH_NOPRICEDEFINED = $saveconf_SUPPLIER_ORDER_WITH_NOPRICEDEFINED;
	    }
	    $action = 'showdispatchresult';
	}
	
}


/*
 * View
 */
	
$title = $langs->trans('ProductsToOrder');
$helpurl = 'EN:Module_Stocks_En|FR:Module_Stock|';
$helpurl .= 'ES:M&oacute;dulo_Stocks';
llxHeader('', $langs->trans('Dispath'), $helpurl, 'commercial', 0, 0, '', array('/supplierorderfromorder/css/style.css'));


$head = array();
$head[0][0] = dol_buildpath('/supplierorderfromorder/ordercustomer.php?id='.$fromid,1);
$head[0][1] = $title;
$head[0][2] = 'supplierorderfromorder';

$head[1][0] = dol_buildpath('/supplierorderfromorder/dispatch_to_supplier_order.php',1).'?from='.$from.'&fromid='.$fromid;
$head[1][1] = $title;
$head[1][2] = 'supplierorderfromorder_dispatch';


dol_fiche_head($head, 'supplierorderfromorder_dispatch', $langs->trans('Replenishment'), 0, 'stock');




$productDefault = new Product($db);

$thisUrlStart = dol_buildpath('supplierorderfromorder/dispatch_to_supplier_order.php',1);

if( ($action === 'prepare' || $action == 'showdispatchresult')  && !empty($origin->lines)){
    
    $origin->fetch_optionals();
    //$TlistContact = $origin->liste_contact(-1,'external',0,'SHIPPING');
    
    ?>
    <table width="100%" border="0" class="notopnoleftnoright" style="margin-bottom: 6px;">
        <tbody>
            <tr>
                <td class="nobordernopadding valignmiddle">
                    <img src="<?php echo dol_buildpath('theme/eldy/img/title_generic.png',2); ?>" alt="" class="hideonsmartphone valignmiddle" id="pictotitle">
                    <div class="titre inline-block"><?php  print $langs->trans('PrepareFournDispatch'); ?> <?php  print $origin->getNomUrl(); ?></div>
                </td>
           </tr>
       </tbody>
    </table>
<?php 
    
    $TFournLines = array();
    
    $countErrors = 0;
    foreach ( $TDispatch as $lineId => $infos)
    {
        if($infos['status'] < 0) $countErrors ++;
    }
    
    if($countErrors>0)
    {
        setEventMessage('Il y a des erreurs...', 'errors');
    }


    
    print '<div class="div-table-responsive">';
    print '<form id="crea_commande" name="crea_commande" action="'.$thisUrlStart.'" method="POST">';
    
    print '<input type="hidden" name="from" value="'.$from.'" />';
    print '<input type="hidden" name="fromid" value="'.$fromid.'" />';
    
    print '<table width="100%" class="noborder noshadow" >';
    
    $totalNbCols = 7;
    print '<thead>';
    print '   <tr >';
    print '       <th class="liste_titre" >' . $langs->trans('Description') . '</th>';
    print '       <th class="liste_titre center" >' . $langs->trans('Commande client') . '</th>';
    print '       <th class="liste_titre center" >' . $langs->trans('Stock_reel') . '</th>';
    print '       <th class="liste_titre center" >' . $langs->trans('Stock_theorique') . '</th>';
    print '       <th class="liste_titre" >' . $form->textwithtooltip($langs->trans('QtyToOrder'), $langs->trans('QtyToOrderHelp'),2,1,img_help(1,'')) . '</th>';
    print '       <th class="liste_titre" >' . $langs->trans('Supplier') . '</th>';
    
    if(!empty($conf->global->SOFO_USE_DELIVERY_CONTACT)){
        $totalNbCols++;
        print '       <th class="liste_titre" >' . $form->textwithtooltip($langs->trans('Delivery'), $langs->trans('DeliveryHelp'),2,1,img_help(1,'')) . '<br/><small style="cursor:pointer;" id="emptydelivery" ><i class="fa fa-truck" ></i>Vider</small></th>';
    }
//    print '       <th class="liste_titre" >' . $form->textwithtooltip($langs->trans('Price'), $langs->trans('dispatch_Price_Help'),2,1,img_help(1,'')) . '</th>';
    print '       <th class="liste_titre" style="text-align:center;" ><input id="checkToggle" type="checkbox" name="togglecheckall" value="0"   ></th>';
    print '   </tr>';
    print '</thead>';
    
    
    if(!empty($origin->lines)){
        print '<tbody>';
        
        
        $last_title_line_i = -1; // init last title i
        
        
        foreach($origin->lines as $i => $line){ 

            // do not import services
            if($line->product_type === 1)
            {
                continue;
            }
            
            $errors = 0;
            $disable = 0;
            
            $line->fournUnitPrice = ''; // leave empty to use database fourn price
            $line->fournPrice = ''; // leave empty to use database fourn price * qty
            $line->fk_soc_fourn = 0; // soc id for supplier order
            $line->fk_soc_dest = 0;  // soc id for supplier order delivery contact
            
            // Load Product
            if(!empty($line->fk_product))
            {
                $product = new Product($db);
                $product->fetch($line->fk_product);
                $line->product = $product;
                $line->label = $product->label;
                $product->load_stock('');
            }
            
            // TODO : Detect subtotal lines without subtotal
            $line->isModSubtotalLine = 0;
            if(class_exists('TSubtotal') && TSubtotal::isModSubtotalLine($line)){
                $line->isModSubtotalLine = 1;
                
                // Use to know line's title
                if(TSubtotal::isTitle($line)){
                    $last_title_line_i = $i; // set last title $i
                }elseif( TSubtotal::isSubtotal($line)){
                    $last_title_line_i = -1; // reset last title $i
                }
                
            }
            
            
            // Add background color to line
            if(!empty($line->isModSubtotalLine))
            {
                $lineStyleColor = '#eeffee';
            }
            elseif(!empty($TDispatch[$line->id]))
            {
                if($TDispatch[$line->id]['status'] < 0){
                    $lineStyleColor ='#ecb1b1';
                }
                else
                {
                    $lineStyleColor ='#b8ecb1';
                }
            }
            
            $lineStyle = '';
            if(!empty($lineStyleColor))
            {
                $lineStyle .= 'background:'.$lineStyleColor.';';
            }
            
            
            
            // START NEW LINE
            print '<tr class="oddeven" data-lineid="'.$line->id.'" style="'.$lineStyle.'" >';
            
            // COL DESC
            print '<td class="col-desc" >';
            if(!empty($line->product)){
                print '<strong>'. $line->product->getNomUrl(1).'</strong> ';
            }
            
            if($line->isModSubtotalLine){
                print '<strong>'. $line->label.'</strong> ';
            }
            else {
                print empty($line->label)?$line->desc:$line->label;
            }
            
            
            if(!empty($TDispatch[$line->id]) &&  $TDispatch[$line->id]['status'] < 0)
            {
                print '<p>'.$TDispatch[$line->id]['msg'].'</p>';
            }
            
            print '</td>';
            
            // Check if this line is allready dispatched
            $searchSupplierOrderLine = getLinkedSupplierOrdersLinesFromElementLine($line->id);
            
            
            
            // GET NOMENCLATURE (show display nomenclature lines for print part)
            $nomenclatureViewToHtml = '';
            if(!empty($line->fk_product)){
                $deep = 0; $maxDeep = 1; $qty = 1 ; //$line->qty
                $Tnomenclature = sofo_nomenclatureProductDeepCrawl($line->id, $line->element, $line->fk_product,$line->qty, $deep, $maxDeep);
                
                if(!empty($Tnomenclature)){
                    $param = array(
                        'colspan' => $totalNbCols
                        ,'searchSupplierOrderLine' => $searchSupplierOrderLine // Si la ligne parente à fait l'objet d'un traitement (ou un produit issue de la nomenclature)
                    );
                    
                    $nomenclatureViewToHtml = _nomenclatureViewToHtml($line, $Tnomenclature, $param);
                }
            }
            
            
            // 
            if(!$line->isModSubtotalLine  && empty($searchSupplierOrderLine))
            {
                
                
                $line->fk_soc_dest = $origin->socid;
                
                // récupération du contact de livraison
                if(!empty($TlistContact))
                {
                    $select_shipping_dest_filter = $TlistContact[0]['id'];
                    $line->fk_soc_dest = $TlistContact[0]['socid'];
                }
                
                
                
                
                
                
                // QTY
                print '<td class="center col-qtyordered"  ><strong title="'.$langs->trans('clicToReplaceQty').'" class="addvalue2target classfortooltip" style="cursor:pointer" data-value="'.$line->qty.'" data-target="#qty-'.$line->id.'"  >'.$line->qty.'</strong></td>';
                
                // STOCK REEL
                print '<td  class="center col-realstock">';
                if(!empty($line->fk_product) && $line->product_type == Product::TYPE_PRODUCT)
                {
                    print $product->stock_reel;
                }
                print '</td>';
                
                // STOCK THEORIQUE
                print '<td  class="center col-theoreticalstock">';
                if(!empty($line->fk_product) && $line->product_type == Product::TYPE_PRODUCT)
                {
                    print $product->stock_theorique;
                }
                print '</td>';
                
                if(!empty($line->fk_product))
                {
                    $stocktheoBeforeOrder = $product->stock_theorique + $line->qty;
                }
                else {
                    $stocktheoBeforeOrder = 0;
                }
                
                
                $qty2Order = $line->qty;
                
                if($line->product_type == Product::TYPE_PRODUCT)
                {
                    
                    if( $stocktheoBeforeOrder - $line->qty >= 0){
                        $qty2Order = 0;
                    }
                    elseif($stocktheoBeforeOrder > 0)
                    {
                        $qty2Order = abs($stocktheoBeforeOrder - $line->qty) ;
                    }
                    else
                    {
                        $qty2Order =  $line->qty ;
                    }
                    
                    if($qty2Order>$line->qty){
                        $qty2Order = $line->qty;
                    }
                }
                
                
                // QTY
                if(!empty($Tqty[$line->id])){
                    $qty2Order = $Tqty[$line->id];
                }
                print '<td class="center col-qtytoorder" >';
                print '<input id="qty-'.$line->id.'" class="qtyform col-qtytoorder" data-lineid="'.$line->id.'" type="number" name="qty['.$line->id.']" value="'.$qty2Order.'" min="0"  >';
                print '</td>';
                
               
   
                
                /*
                 * SELECTION FOURNISSEUR
                 */
                
                print '<td class="col-fourn" >';
                if(!empty($line->fk_product))
                {
                    // get min fourn price id
                    $minFournPriceId = sofo_getFournMinPrice($line->fk_product);
                    
                    
                    if(!empty($Tproductfournpriceid[$line->id])){
                        $minFournPriceId = $Tproductfournpriceid[$line->id];
                    }
                    
                    print $form->select_product_fourn_price($line->fk_product, 'productfournpriceid['.$line->id.']', $minFournPriceId);
                    
                }
                else
                {
                    // In case of a free line
                    
                    print $form->select_company(GETPOST('fk_soc_fourn_'.$line->id,'int'),'fk_soc_fourn_'.$line->id, '',1,'supplier');
                    print ' &nbsp;&nbsp;&nbsp; <input class="unitPriceField" type="number" name="fournUnitPrice['.$line->id.']" value="'.price2num($line->pa_ht).'" min="0" step="any" placeholder="'.$langs->trans('UnitPrice').'" >&euro; ';
                    //print $form->selectUnits($line->fk_unit, 'units['.$line->id.']', 1);
                    
                    $productDefault->fk_unit = $line->fk_unit;
                    print $productDefault->getLabelOfUnit();
                }
                
                // Additionnal options for nomenclature
                if(!empty($Tnomenclature))
                {
                    print '<i class="sofo_pointeur fa fa-plus classfortooltip moreoptionbtn" data-target="#moreoption'.$line->id.'"  title="'.$langs->trans('MoreOptions').'"  ></i>';
                    print '<div class="moreoptionblock" id="moreoption'.$line->id.'" >';
                    
                    print '<fieldset><legend>'.$langs->trans('Nomenclature').'</legend>';
                    $selectFournFormName = 'force_nomenclature_fk_soc_fourn_'.$line->id;
                    $selectFournForm = $form->select_company(GETPOST($selectFournFormName,'int'),$selectFournFormName, '',1,'supplier', $forcecombo=0, array(), 0, 'minwidth100', '', '', 2);
                    print '<div>'.$selectFournForm.' '.$form->textwithtooltip( $langs->trans('ForceFourn') , $langs->trans('ForceFournHelp'),2,1,img_help(1,'')) .'</div>';
                    
                    print '</fieldset>';
                    
                    print '</div>';
                }
                
                print '</td>';
                
                
                
                
                /*
                 * SELECTION CONTACT DE LIVRAISON
                 */
                if(!empty($conf->global->SOFO_USE_DELIVERY_CONTACT))
                {
                    print '<td>';
                    
                    if(isset($TShipping[$line->id])){
                        $select_shipping_dest_filter = $TShipping[$line->id];
                    }
                    
                    $selecContactRes = $form->select_contacts($line->fk_soc_dest,$select_shipping_dest_filter,'shipping['.$line->id.']',1);
                    if(empty($selecContactRes))
                    {
                        $socDest = new Societe($db);
                        $socDest->fetch($line->fk_soc_dest);
                        
                        print '<br/>'.$socDest->getNomUrl(1); //.' : <span class="error" >'.$langs->trans('ProductionFactoryShippingNotDefined').'</span>';
                    }
                    print '</td>';
                }
                
                
                // UNIT PRICE
                //print '<td ><input type="number" name="fournUnitPrice['.$line->id.']" value="'.$line->fournUnitPrice.'"  required ></td>';
                

                // LINE PRICE
                //print '<td ><input type="number" name="fournPrice['.$line->id.']" value="'.$line->fournPrice.'" ></td>';
                
                // CHECKBOX
                print '<td  style="text-align:center;"  >';
                if(!$disable)
                {
                    $check = true;
                    if(!empty($TChecked[$line->id])){
                        $check = true;
                    }
                    elseif(empty($qty2Order)||empty($line->fk_product)){
                        $check = false;
                    }
                    
                    print '<input id="linecheckbox'.$line->id.'" class="checkboxToggle" type="checkbox" '.($check?'checked':'').' name="checked['.$line->id.']" value="'.$line->id.'">';
                }
                else
                {
                    print $langs->trans('CheckErrorsBeforeInport');
                }
                
                print '</td>';
            
            }
            else {
                print '<td colspan="7" >';
                if(!empty($searchSupplierOrderLine))
                {
                    $TAllreadyDispatchedSuppOrderLines = array();// key = line id  and value = order id
                    foreach($searchSupplierOrderLine as $searchSupplierOrderLineId)
                    {
                        // récupération de la commande correspondante
                        $commandeFournisseurLigne = new CommandeFournisseurLigne($db);
                        $resFetchCommandeFournisseurLigne = (int) $commandeFournisseurLigne->fetch($searchSupplierOrderLineId);
                        if($resFetchCommandeFournisseurLigne > 0){
                            
                            // petite opti
                            if(!in_array($commandeFournisseurLigne->fk_commande, $TAllreadyDispatchedSuppOrderLines))
                            {
                                
                                $existingFournOrder = New CommandeFournisseur($db);
                                $existingFournOrder->fetch($commandeFournisseurLigne->fk_commande);
                                
                                print !empty($TAllreadyDispatchedSuppOrderLines)?', ':'';
                                
                                print $existingFournOrder->getNomUrl(1);
                                
                                $existingFournOrder->fetch_thirdparty();
                                if(!empty($existingFournOrder->thirdparty))
                                {
                                    print ' '.$existingFournOrder->thirdparty->getNomUrl(1,'supplier');
                                }
                                
                                /*if(GETPOST('forcedeletelinked','int')){
                                 $line->deleteObjectLinked();
                                 }*/
                            }
                            
                            $TAllreadyDispatchedSuppOrderLines[$commandeFournisseurLigne->id] = $commandeFournisseurLigne->fk_commande;
                            
                            
                        }
                    }
                    
                    if(!empty($TAllreadyDispatchedSuppOrderLines))
                    {
                        print '<br/> '.$langs->trans('AllreadyDispatched'.(count($TAllreadyDispatchedSuppOrderLines)>1?'s':''));
                        
                        // Display a link to show nomenclature part
                        if(!empty($nomenclatureViewToHtml))
                        {
                            print ' <small style="cursor:pointer" class="toggle-display-nomenclature-detail" data-target="'.$line->id.'" >'.$langs->trans('DisplayNomenclature').'</small>';
                        }
                    }
                    
                }
                elseif(!$line->isModSubtotalLine && $line->product_type === 1)
                {
                    print $langs->trans('servicesAreNotDispatch');
                }
                elseif(!$line->isModSubtotalLine && empty($line->fk_product))
                {
                    print $langs->trans('ProductToOrderManuellement');
                }
                print '</td>';

            }
            
            print '</tr>';
            
            
            // DISPLAY NOMENCLATURE LINES
            print $nomenclatureViewToHtml;
            
            
            
        }
        
        print '</tbody>';
    } 
    
    print '</table>';
    
    print '<div style="clear:both; text-align: left; display:none;" ><input id="bypassjstests" type="checkbox" name="bypassjstests" value="1"> <label for="bypassjstests" >'.$langs->trans('ForceDispatch').'.</label></div>';
    
    print '<div style="text-align: right;" ><button  type="submit" name="action" value="dispatch" >'.$langs->trans('Dispatch').' <i class="fa fa-arrow-right"></i></button></div>';
    
    print '</form>';
    print '</div>';
    
    // Inclusion du fichier script de pré-validation des formulaires
    print '<script type="text/javascript" src="'.dol_buildpath('supplierorderfromorder/js/dispatch_to_supplier_order.js.php',1).'"></script>';
    
}






llxFooter('');




function _nomenclatureViewToHtml($line, $TNomenclatureLines, $overrideParam = array())
{
    global $db,$langs, $TChecked, $nomenclatureI,$form, $TDispatchNomenclature;
    global $TNomenclature_productfournpriceid, $TNomenclature_qty, $TNomenclature_fournUnitPrice;
    
    
    if(empty($TNomenclatureLines)){
        return '';
    }
    
    
    
    $print='';
    
    $param = array(
        'colspan' => 7
        ,'searchSupplierOrderLine'=>0
    );
    
    // Replace defaults param
    if(!empty($overrideParam) && is_array($overrideParam)){
        foreach($overrideParam as $k => $v){
            $param[$k] = $v;
        }
    }
    
    $displayLine = '';
    if(!empty($param['searchSupplierOrderLine']))
    {
        $displayLine = 'display:none;';
    }
    
    //$print.= '<tr class="nomenclature-row" data-parentlineid="'.$line->id.'" style="'.$displayLine.'" ><td colspan="'.$param['colspan'].'" ></td></tr>';
    
    foreach ($TNomenclatureLines as $i => $productPart)
    {
        // FIXME : $productPart->id is empty so I use $i instead of $productPart->id
        $nomenclatureI = $line->id.'_'.$i;
        
        
        
        $qty2Order=0;
        $disable = 0;
        
        $colspan = $param['colspan'];

        $product =false;
        if(!empty($productPart['fk_product']))
        {
            $product = new Product($db);
            if($product->fetch($productPart['fk_product']) < 1)
            {
                $product =false;
            }
        }
        
        if($productPart['element'] != 'workstation' && $product )
        {
            
            $style = 'font-size:0.9em;color:#666;';
            $print.= '<tr class="nomenclature-row" data-parentlineid="'.$line->id.'"  style="'.$displayLine.$style.'">';
            
            // DESC
            $print.= '<td class="col-desc" ><i class="fa fa-caret-right" ></i> ';
            $colspan--;
            $productRefView = $product->getNomUrl(1);
            $productRefView = '<strong>'.$productRefView.'</strong> ';
            $productPart['infos']['label'] = $productRefView.$product->label.' '.$productPart['infos']['label'];
            $print.= $productPart['infos']['label'];
            if(!empty($productPart['infos']['desc'])){ $print.= ' '.$productPart['infos']['desc']; }
            $print.= '<input type="hidden" name="nomenclature_productfournproductid['.$line->id.']['.$nomenclatureI.']" value="'.$product->id.'" />';
            
            if(!empty($TDispatchNomenclature[$line->id][$nomenclatureI]))
            {
                $print.=  $TDispatchNomenclature[$line->id][$nomenclatureI]['msg'];
            }
            
            $print.= '</td>';
            
            // QTY
            $colspan--;
            $print.=  '<td class="center col-qtyordered" >';
            $print.=  '<strong title="'.$langs->trans('clicToReplaceQty').'" class="addvalue2target classfortooltip" style="cursor:pointer" data-value="'.$productPart['infos']['qty'].'" data-target="#qty-'.$line->id.'-n'.$nomenclatureI.'"  >'.$productPart['infos']['qty'].'</strong>';
            $print.=  '</td>';
            
            // STOCK REEL
            $colspan--;
            $print.=  '<td  class="center col-realstock">';
            $print.=  $product->stock_reel;
            $print.=  '</td>';
            
            // STOCK THEORIQUE
            $colspan--;
            $print.=  '<td  class="center col-theoreticalstock">';
            $print.=  $product->stock_theorique;
            $print.=  '</td>';
            
            
            // QTY TO ORDER
            $colspan--;
            if(!empty($TNomenclature_qty[$line->id][$nomenclatureI])){
                $qty2Order = $TNomenclature_qty[$line->id][$nomenclatureI];
            }
            $print.= '<td class="center col-qtytoorder" >';
            $print.= '<input id="qty-'.$line->id.'-n'.$nomenclatureI.'" class="qtyform-nomenclature col-qtytoorder" data-lineid="'.$line->id.'" data-nomenclature="'.$nomenclatureI.'" type="number" name="nomenclature_qty['.$line->id.']['.$nomenclatureI.']" value="'.$qty2Order.'" min="0"  >';
            $print.= '</td>';
            
            
            // SELECTION FOURNISSEUR
            $colspan--;
            $print.=  '<td class="col-fourn" >';
            if(!empty($line->fk_product))
            {
                // get min fourn price id
                $minFournPriceId = sofo_getFournMinPrice($product->id);
                
                if(!empty($TNomenclature_productfournpriceid[$line->id][$nomenclatureI])){
                    $minFournPriceId = $TNomenclature_productfournpriceid[$line->id][$nomenclatureI];
                }
                
                if($minFournPriceId>0)
                {
                    $print.=  $form->select_product_fourn_price($product->id, 'nomenclature_productfournpriceid['.$line->id.']['.$nomenclatureI.']', $minFournPriceId);
                }
                else
                {
                    $print.= $langs->trans("NoSupplierPriceDefinedForThisProduct").'<br/>';
                    $name = 'fk_soc_fourn_'.$line->id.'_n'.$nomenclatureI;
                    $selected=GETPOST($name,'int');
                    $print.= $form->select_company($selected, $name, '', 1, 'supplier', $forcecombo=0, array(), 0, 'minwidth100', '', '', 2);
                    
                    
                    $fournPrice = '';
                    if(!empty($TNomenclature_fournUnitPrice[$line->id][$nomenclatureI])){
                        $fournPrice =  $TNomenclature_fournUnitPrice[$line->id][$nomenclatureI];
                    }
                    
                    
                    $print.= ' &nbsp;&nbsp;&nbsp; <input class="unitPriceField" type="number" name="nomenclature_fournUnitPrice['.$line->id.']['.$nomenclatureI.']" value="'.price2num($fournPrice).'" min="0" step="any" placeholder="'.$langs->trans('UnitPrice').'" >';
                    $print.= $product->getLabelOfUnit();
                }
                
                
                
            }
            $print.=  '</td>';
            
            // COLSPAN
            $colspan--; // FOR checkbox col
            if($colspan>0)
            {
                $print.= '<td colspan="'.$colspan.'" ></td>'; 
            }
            
            // CHECKBOX
            $print.= '<td  style="text-align:center;"  >';
            if(!$disable)
            {
                $check = true;
                if(!empty($TChecked[$line->id])){
                    $check = false;
                }
                elseif(empty($qty2Order)){
                    $check = false;
                }
                
                $print.=  '<input id="linecheckbox'.$nomenclatureI.'-nomenclature'.''.'" class="checkboxToggle checkboxToggle-nomenclature" type="checkbox" '.($check?'checked':'').' name="checkedNomenclature['.$line->id.']['.$nomenclatureI.']" value="'.$nomenclatureI.'">';
            }
            else
            {
                $print.=  $langs->trans('CheckErrorsBeforeInport');
            }
            
            
            $print.= '</tr>';
            
            // TODO HOW TO USE AND DISPLAY CHILDREN ?
            /*if(!empty($productPart['children'])){
             bcb_nomenclatureViewToHtml($productPart['children']);
             }*/
        }
        
    }
    
    //$print.= '<tr class="nomenclature-row" data-parentlineid="'.$line->id.'" style="'.$displayLine.'" ><td colspan="'.$param['colspan'].'" ></td></tr>';
    
    return $print;
}
