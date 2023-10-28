insert into mining_plans(
    name,
    months,
    unit_name,
    total_units,
    min_ord_units,
    unit_price,
    discount_perc_every,
    discount_max,
    enabled
) values(
    'BPX (solo) + Chia (pooled) double mining'
    60,
    'plot',
    1500,
    10,
    1.88,
    20,
    30,
    TRUE
);
















insert into mining_assets_of_plan(planid, assetid, prec, priority)
values(1, 'BPX', 0, 1);
insert into mining_assets_of_plan(planid, assetid, prec, priority)
values(1, 'XCH', 2, 2);
